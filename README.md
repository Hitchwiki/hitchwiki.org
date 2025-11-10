<div align="center">

# 🌍 Hitchwiki  

**The Guide to Hitchhiking the World**  

✌️ A collaborative knowledge base for hitchhikers, by hitchhikers ✌️  

[Website](https://hitchwiki.org)

</div>

---

## 📖 About Hitchwiki  

Hitchwiki is a global, community-driven project that gathers knowledge about **hitchhiking and ultra-low-budget travel**.

Since 2005, thousands of travelers have shared their experiences, tips, and routes — creating a **living guide to hitchhiking the world**.  

- 📚 **Community Wiki** – 4,500+ articles on how, where, with whom and why to hitchhike
- 🙌 **Global Community** – Connect with travelers, share stories, and join events  

Hitchwiki is part of a wider network of open projects:  
👉 [Nomadwiki](https://nomadwiki.org) • [Trashwiki](https://trashwiki.org) • [Trustroots](https://www.trustroots.org)  

## 🚀 This Repository

This is the code that Hitchwiki.org is running on. Currently, running this wiki locally might be a bit difficult if you are unfamiliar with MediaWiki imports.

Eventually, this repository should release versioned Docker images with all necessary scripts, configuration, dumps and files for people to host their own local or cloned wikis.

As we are currently in the middle of an extensive upgrade, it might still contain necessary scripts and files for this process.

## Installation

### Getting Started with Docker

This project uses Docker Compose to run MediaWiki with MySQL.

#### 📦 Requirements

- [Docker](https://www.docker.com/get-started)
- [Docker Compose](https://docs.docker.com/compose/install/)

#### 🛠️ Setup Instructions

1. **Clone the repository with submodules**:
   ```bash
   git clone --recurse-submodules https://github.com/Hitchwiki/hitchwiki.org.git

   cd hitchwiki.org
   ```

   If you have already cloned the repository without submodules, initialize them:
   ```bash
   git submodule update --init --recursive
   ```

2. **Configure environment**:
   ```bash
   cp example.env .env
   ```
   Edit `.env` to set your desired configuration, such as database credentials and site settings.

3. **Set up the database**:
   The project is currently undergoing a multi-hop MediaWiki upgrade process. To set up the database with existing data:

   - Obtain the latest SQL dumps (see section below) and place them as `./hitchwiki_*.sql.gz` files in the project root. The naming is important as the databases will be named after the files.

   - Run the upgrade script:
     ```bash
     ./upgrade-mw.sh
     ```
     This will automatically upgrade through multiple MediaWiki versions (1.33 to 1.44).

4. **Start the services (optional)**:
   After running the upgrade, it will keep the Docker container running with a (hopefully) fully functional wiki.

   For a production-like setup after upgrade, you can use:
   ```bash
   docker-compose -f docker-compose.yml up -d
   ```

5. **Access the wiki**:
   Open http://localhost:8080 in your browser.

## Dumps

> **⚠️ As the upgrade process is designed to be used on a production database**, in order to go through it with this repository, you will have to obtain one of the full nightly dumps from someone who has server access.

We're providing public backups of XML dumps and images, at https://hitchwiki.org/dumps.

Code and SQL dumps are also backed up to several machines controlled by @guaka and @robokow. If you have server access you could do the same, you want to back up these directories:

- `/var/www/hitchwiki` (especially`/var/www/hitchwiki/htdocs/wiki/images/`)
- `/var/backups/mysql/sqldump/`

`backupninja` runs nightly through cron and creates SQL dumps of all databases in `/var/backups/mysql/sqldump/`. It can also be useful to create database dumps before attempting an upgrade.

## Usage / Structure

### Project Structure

- `wiki/`: MediaWiki installation directory containing static and configuration files for the wiki.
- `tools/`: Various scripts for maintenance, dumps, and extension management (e.g., `upgrade-extensions` for checking out versioned submodule branches).
- `patches/`: Custom patches for MediaWiki upgrade process to override with fixes or changes (to be removed after deployment).
- `extensions/`: Git submodules of used extensions; bundled extensions are not included here.
- `sql/`: Databases to be imported during the upgrade process.
- `backups/`: Used to save database snapshots during the upgrade process.
- `docker-compose.yml`: Spins up a versioned Hitchwiki and database container, mounting configuration and patch files. The database is configured with a default root user for scripts.
  - `Dockerfile` prepares a production-ready container with skins, extensions, static and configuration files bundled.
  - `docker-compose.prod.yml`: Spins up production-ready containers without mounted overwrites.
- `upgrade-mw.sh`: Main upgrade script executed on the host machine; hops versions automatically and executes all steps for all languages.
- `import-db.sh`: Allows for a quick reset of the database from `./sql` from the host machine.

### Database Structure

- MediaWiki uses MySQL with a separate database for each language (e.g. `hitchwiki_en`, `hitchwiki_de`, `hitchwiki_tr`, etc).
- A few tables are shared across languages: Users, Interwiki, SpoofUser, Uploads, etc.

## Troubleshooting

### .env File Not Loading

If you're experiencing issues with environment variables not being loaded from the .env file, the problem is most likely related to the PHP config or the file not existing. [Many PHP installations also do not load environment variables by default](https://github.com/vlucas/phpdotenv?tab=readme-ov-file#troubleshooting).

By default, the .env file should be located in the repository root. It can also be located one folder above, or in a parallel folder called `private`.

### Permissions

The database user needs access to **all language** databases. The `upgrade-mw.sh` or `import-db.sh` scripts should do this automatically. During the upgrade process, the root user has a simple password that can be used in the CLI for simplification.

Without proper permissions, the installation and upgrade processes will fail. Try to verify that your user is correctly configured in the `.env` file and has been granted rights.

### Branch Issues

Submodules can be hard to work with and the script for automatically checking out branches might not work as intended. If a submodule is incorrectly set up, it can end up changing the branch on the parent git repository instead.

If you encounter any such issues, verify that all submodules are properly set up and contain their own git repositories. If the issue persists, it likely is in connection with the `upgrade_extensions.sh` script.

## Contact & Credits

### Contact

* [#hitchhiking @ freenode](irc://irc.freenode.net/#hitchhiking)
* Signal/Matrix: `#hitchhiking:chagai.website`
* [Hitchwiki GitHub Organization](https://github.com/Hitchwiki/)

### Contributors

* [Guaka](https://github.com/guaka)
* [Till](https://github.com/tillwenke)
* [Leon Weber](https://github.com/leon-wbr)
* [zrthstr](https://github.com/zrthstr)