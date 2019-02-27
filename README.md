# Discord whole channel archiver
This application can archive whole Discord channels (complete with all attachments, embeds, and messages) out to month by month to HTML files for offline viewing.

### Requirements
To run this application, you will need the following installed:

- PHP 5.6+ CLI (tested on PHP 5.6.38)
- Linux (preferred and tested on CentOS 7.5.1804)

### Setup
This project uses composer to manage dependencies and includes the package manager (composer.phar)

To download all dependencies, please run the following command from the project directory:
```
./composer.phar update
```

Once all dependencies are installed, you are ready to set up the config. To set up your config:

- Copy the included default config file under `config/config.ini.default` as `config/config.ini`.
- Edit `config/config.ini` and replace the fake `discord_token` with your Bot or User Discord login Token.

### Usage of application
#### To set the channels you want archvied:
- Edit `config/config.ini` and add/remove channels IDs to archive by setting `channels.1`,`channels.2`,`channels.3` etc with the channel IDs of the channels to be archived.

#### To start the archival:
- Please run the following command from the project directory:
```
./archiver.sh
```
This will start archiving the given channels listed in the config. Once finished, all HTML files will be located in `archives` and all attachments are saved to `archives\att`.

### Credits

Thanks to [meowsome](https://github.com/meowsome) for the spot on HTML and CSS used in the templates!