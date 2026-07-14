<?php

namespace MediaWiki\Extension\CentralLangLinks\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Seed page_translations from the existing per-wiki langlinks tables.
 *
 * Reads every language wiki's `langlinks`, treats each interlanguage link as an
 * edge between two (language, title) nodes, and computes connected components
 * (union-find). Each component is one concept. The concept is keyed by its
 * English title when the component contains an English page; otherwise a
 * deterministic representative (lowest language code) is used.
 *
 * This unions the whole family, so today's asymmetric/one-directional links are
 * healed automatically.
 *
 * Dry run by default — pass --save to (re)build the table. --save performs a
 * full rebuild: it deletes all existing rows first.
 *
 * Run against the shared wiki: --wiki=en
 *
 *   php maintenance/run.php \
 *     extensions/CentralLangLinks/maintenance/seedFromLangLinks.php --wiki=en
 *   ... add --save to write.
 */
class SeedFromLangLinks extends Maintenance {

	/** @var array<string,string> union-find parent map: node => node */
	private array $parent = [];
	/** @var array<string,array{0:string,1:string}> node => [lang, titleKey] */
	private array $nodes = [];
	/** @var list<array{0:string,1:string}> directed edges [sourcePageNode, targetNode] */
	private array $edges = [];

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CentralLangLinks' );
		$this->addDescription(
			'Seed the central page_translations table from existing per-wiki langlinks.'
		);
		$this->addOption( 'save',
			'Actually write the table (full rebuild). Without this, dry run only.' );
		$this->addOption( 'samples',
			'How many sample concepts to print (default 10).', false, true );
		$this->addOption( 'issues',
			'Print the full no-English and title-conflict reports for review.' );
		$this->addOption( 'blame',
			'For each contaminated cluster, name the source page/wiki carrying the bad link.' );
	}

	public function execute() {
		$langs = array_keys( $GLOBALS['hwLanguages'] );
		$family = array_fill_keys( $langs, true );
		$dbw = $this->getDB( DB_PRIMARY );
		// Current wiki is en; derive the shared DB-name base, e.g. "hitchwiki".
		$dbBase = preg_replace( '/_[a-z-]+$/', '', $this->getConfig()->get( 'DBname' ) );

		$pagesScanned = 0;
		$edges = 0;

		foreach ( $langs as $lang ) {
			$db = $dbBase . '_' . $lang;
			// langlinks is per-wiki (not shared); read each wiki's copy cross-DB.
			// $db and $lang come from trusted config, so the identifiers are safe.
			$res = $dbw->query(
				"SELECT p.page_title AS pt, ll.ll_lang AS lang, ll.ll_title AS t " .
				"FROM `$db`.page p " .
				"JOIN `$db`.langlinks ll ON ll.ll_from = p.page_id " .
				"WHERE p.page_namespace = 0 AND p.page_is_redirect = 0",
				__METHOD__
			);
			foreach ( $res as $row ) {
				$pagesScanned++;
				// Only link within the Hitchwiki language family.
				if ( !isset( $family[$row->lang] ) ) {
					continue;
				}
				// Skip stray empty link targets, e.g. a bare [[hr:]].
				if ( trim( $row->t ) === '' || trim( $row->pt ) === '' ) {
					continue;
				}
				$a = $this->node( $lang, $row->pt );
				$b = $this->node( $row->lang, $row->t );
				$this->union( $a, $b );
				// $a is the page that declares the [[$row->lang:$row->t]] link.
				$this->edges[] = [ $a, $b ];
				$edges++;
			}
		}

		// Group nodes into connected components.
		$components = [];
		foreach ( array_keys( $this->nodes ) as $node ) {
			$components[$this->find( $node )][] = $node;
		}

		$rows = [];          // "concept\x1flang" => [concept, lang, title]
		$noEnglish = 0;
		$titleConflicts = 0;
		$keyCollisions = 0;
		$concepts = 0;
		$conflictReport = [];    // detailed same-lang title conflicts
		$noEnglishReport = [];   // clusters lacking an English page

		foreach ( $components as $nodeList ) {
			// One title per language (first wins; record conflicting extras).
			$byLang = [];
			$conflicts = [];
			foreach ( $nodeList as $node ) {
				[ $lang, $title ] = $this->nodes[$node];
				if ( isset( $byLang[$lang] ) ) {
					if ( $byLang[$lang] !== $title ) {
						$titleConflicts++;
						$conflicts[] = "$lang: {$byLang[$lang]} | $title";
					}
					continue;
				}
				$byLang[$lang] = $title;
			}
			// A concept needs at least two languages to be a translation.
			if ( count( $byLang ) < 2 ) {
				continue;
			}
			// English is the ground truth; fall back to a deterministic rep.
			$hasEnglish = isset( $byLang['en'] );
			if ( $hasEnglish ) {
				$concept = $byLang['en'];
			} else {
				ksort( $byLang );
				$concept = reset( $byLang );
			}
			// A same-language conflict means transitive union-find has fused two
			// distinct concepts through a stray bad langlink (e.g. Argentina +
			// Chile). We cannot know which link is wrong, so refuse the whole
			// cluster and set it aside for manual review rather than seed a bad
			// merge. Clean clusters (one title per language) are trusted.
			if ( $conflicts ) {
				$conflictReport[] = "[$concept] " . implode( '; ', $conflicts );
				continue;
			}
			if ( !$hasEnglish ) {
				$noEnglish++;
				$members = [];
				foreach ( $byLang as $l => $t ) {
					$members[] = "$l:$t";
				}
				$noEnglishReport[] = "[$concept] " . implode( ', ', $members );
			}
			$concepts++;
			foreach ( $byLang as $lang => $title ) {
				$key = $concept . "\x1f" . $lang;
				if ( isset( $rows[$key] ) ) {
					$keyCollisions++;
					continue;
				}
				$rows[$key] = [
					'pt_concept' => $concept,
					'pt_lang' => $lang,
					'pt_title' => $title,
				];
			}
		}

		$this->output( "Scanned $pagesScanned langlink rows, $edges family edges.\n" );
		$this->output( "Seeding $concepts clean concepts, " . count( $rows ) . " rows.\n" );
		$this->output( "  clusters SKIPPED (same-language conflict, need review): " .
			count( $conflictReport ) . "\n" );
		$this->output( "  (raw conflicting titles across those: $titleConflicts)\n" );
		$this->output( "  clean concepts without an English page: $noEnglish\n" );
		$this->output( "  concept-key collisions (row dropped): $keyCollisions\n" );

		if ( $this->hasOption( 'issues' ) ) {
			$this->output( "\n=== Concepts without an English page (" .
				count( $noEnglishReport ) . ") ===\n" );
			foreach ( $noEnglishReport as $line ) {
				$this->output( "  $line\n" );
			}
			$this->output( "\n=== Same-language title conflicts (kept | dropped) (" .
				count( $conflictReport ) . " clusters) ===\n" );
			foreach ( $conflictReport as $line ) {
				$this->output( "  $line\n" );
			}
			$this->output( "\n" );
		}

		if ( $this->hasOption( 'blame' ) ) {
			$this->printBlame( $components );
		}

		$this->printSamples( array_values( $rows ) );

		if ( !$this->hasOption( 'save' ) ) {
			$this->output( "\nDry run. Re-run with --save to (re)build page_translations.\n" );
			return;
		}

		$this->output( "\nWriting page_translations (full rebuild)...\n" );
		$dbw->begin( __METHOD__ );
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'page_translations' )
			->where( ISQLPlatform::ALL_ROWS )
			->caller( __METHOD__ )
			->execute();
		foreach ( array_chunk( array_values( $rows ), 500 ) as $chunk ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'page_translations' )
				->rows( $chunk )
				->caller( __METHOD__ )
				->execute();
		}
		$dbw->commit( __METHOD__ );
		$this->output( "Done. Wrote " . count( $rows ) . " rows.\n" );
	}

	/**
	 * Register and return the union-find node id for a (lang, title) pair.
	 */
	private function node( string $lang, string $title ): string {
		$key = $this->titleKey( $title );
		$id = $lang . "\x1f" . $key;
		if ( !isset( $this->parent[$id] ) ) {
			$this->parent[$id] = $id;
			$this->nodes[$id] = [ $lang, $key ];
		}
		return $id;
	}

	private function find( string $x ): string {
		while ( $this->parent[$x] !== $x ) {
			$this->parent[$x] = $this->parent[$this->parent[$x]]; // path halving
			$x = $this->parent[$x];
		}
		return $x;
	}

	private function union( string $a, string $b ): void {
		$ra = $this->find( $a );
		$rb = $this->find( $b );
		if ( $ra !== $rb ) {
			$this->parent[$ra] = $rb;
		}
	}

	/**
	 * Normalise a title to DB-key form: trim and spaces -> underscores.
	 * langlinks stores titles with spaces; page_title uses underscores.
	 */
	private function titleKey( string $title ): string {
		return strtr( trim( $title ), ' ', '_' );
	}

	/**
	 * For each contaminated cluster, name the source page/wiki carrying the
	 * link that fused two concepts. A correct translation set is densely
	 * cross-linked (its edges sit in cycles); a stray wrong link is usually a
	 * lone bridge — a cut edge whose removal splits the cluster. So we report
	 * the cluster's bridge edges, annotated with the page that wrote each.
	 *
	 * @param array<string,list<string>> $components root => node list
	 */
	private function printBlame( array $components ): void {
		$edgesByRoot = [];
		foreach ( $this->edges as $i => $edge ) {
			$edgesByRoot[$this->find( $edge[0] )][] = $i;
		}

		$this->output( "\n=== Blame: link that fused each contaminated cluster ===\n" );
		foreach ( $components as $root => $nodeList ) {
			// Contaminated iff some language resolves to 2+ titles.
			$groups = [];
			foreach ( $nodeList as $node ) {
				[ $lang, $title ] = $this->nodes[$node];
				$groups[$lang][$title] = true;
			}
			$contaminated = false;
			foreach ( $groups as $titles ) {
				if ( count( $titles ) > 1 ) {
					$contaminated = true;
					break;
				}
			}
			if ( !$contaminated ) {
				continue;
			}
			$concept = isset( $groups['en'] )
				? array_key_first( $groups['en'] )
				: array_key_first( $groups[array_key_first( $groups )] );

			$edgeIds = $edgesByRoot[$root] ?? [];
			$bridges = $this->findBridges( $nodeList, $edgeIds );

			$this->output( "  [$concept]\n" );
			if ( !$bridges ) {
				// Two-plus wrong links can form a cycle and hide the bridge.
				$this->output( "      (no lone bridge — reciprocal bad links; review cluster)\n" );
				continue;
			}
			$seen = [];
			foreach ( $bridges as $i ) {
				[ $a, $b ] = $this->edges[$i];
				[ $sl, $st ] = $this->nodes[$a];
				[ $tl, $tt ] = $this->nodes[$b];
				$line = "$sl-wiki: page \"$st\" links [[$tl:$tt]]";
				if ( !isset( $seen[$line] ) ) {
					$seen[$line] = true;
					$this->output( "      $line\n" );
				}
			}
		}
	}

	/**
	 * Return the edge ids (indices into $this->edges) that are bridges of the
	 * subgraph induced by $nodeList / $edgeIds. Undirected; parallel edges
	 * (reciprocal links) form a 2-cycle and are correctly not bridges.
	 *
	 * @param list<string> $nodeList
	 * @param list<int> $edgeIds
	 * @return list<int>
	 */
	private function findBridges( array $nodeList, array $edgeIds ): array {
		// Build undirected adjacency: node => list of [neighbour, edgeId].
		$adj = [];
		foreach ( $nodeList as $n ) {
			$adj[$n] = [];
		}
		foreach ( $edgeIds as $i ) {
			[ $a, $b ] = $this->edges[$i];
			$adj[$a][] = [ $b, $i ];
			$adj[$b][] = [ $a, $i ];
		}

		$disc = [];
		$low = [];
		$timer = 0;
		$bridges = [];

		// Iterative DFS to stay clear of recursion limits on large clusters.
		foreach ( $nodeList as $start ) {
			if ( isset( $disc[$start] ) ) {
				continue;
			}
			// Frame: [node, parentEdgeId, pointer into $adj[node]].
			$stack = [ [ $start, -1, 0 ] ];
			$disc[$start] = $low[$start] = $timer++;
			while ( $stack ) {
				$top = count( $stack ) - 1;
				[ $u, $pe, $ptr ] = $stack[$top];
				if ( $ptr < count( $adj[$u] ) ) {
					$stack[$top][2]++;
					[ $v, $eid ] = $adj[$u][$ptr];
					if ( $eid === $pe ) {
						continue; // don't traverse back up the same edge
					}
					if ( !isset( $disc[$v] ) ) {
						$disc[$v] = $low[$v] = $timer++;
						$stack[] = [ $v, $eid, 0 ];
					} else {
						$low[$u] = min( $low[$u], $disc[$v] );
						$stack[$top][1] = $pe;
					}
				} else {
					array_pop( $stack );
					if ( $top > 0 ) {
						$parent = $stack[$top - 1][0];
						$low[$parent] = min( $low[$parent], $low[$u] );
						if ( $low[$u] > $disc[$parent] ) {
							$bridges[] = $pe; // edge parent–u is a bridge
						}
					}
				}
			}
		}
		return $bridges;
	}

	private function printSamples( array $rows ): void {
		$n = (int)$this->getOption( 'samples', 10 );
		if ( $n <= 0 || !$rows ) {
			return;
		}
		$byConcept = [];
		foreach ( $rows as $r ) {
			$byConcept[$r['pt_concept']][] = $r['pt_lang'] . ':' . $r['pt_title'];
		}
		$this->output( "\nSample concepts:\n" );
		$i = 0;
		foreach ( $byConcept as $concept => $links ) {
			$this->output( "  [$concept] " . implode( ', ', $links ) . "\n" );
			if ( ++$i >= $n ) {
				break;
			}
		}
	}
}

$maintClass = SeedFromLangLinks::class;
require_once RUN_MAINTENANCE_IF_MAIN;
