Everlomp is custom made for the evrPanel on Evernode.

LOMP stands for Linux Openlitespeed MariaDB & PHP.

This system utilize evrPanel secrets to decrypt stored passwords to the current applications utilized in the image.

Load it up to your evrPanel instance with a custom domain, or subdomain to go through the installation process.

During the installation process you set an encryption key as a secret for the stored passwords in your image.

You get access to a mariadb server through phpmyadmin (bruteforce protected), an openlitespeed server (bruteforceprotected), filegator (bruteforce protected) and kopia (bruteforce protected + optional 2FA in UI)

The UI's can be found at
/phpmyadmin/
/openlitespeed/
/filegator
/kopia

Phpmyadmin is the most popular user interface for sql servers. The only customization done for phpmyadmin is monitoring log-in attempts and banning for bruteforces. No customization done to phpmyadmin itself. 
https://github.com/phpmyadmin

Openlitespeed is the fastest open source webserver in the world. You have listeners and vhosts here, no customizations has been done here at all.
https://openlitespeed.org/

Filegator is a wonderful filemanager. It was perfect and required no customizations at all.
https://filegator.io/

Kopia is a wonderful snapshot tool that allow for encrypted and remotely stored backups. Everlomp have added four functions to the webui of kopia.
1. The ability to change password for the ui and repositories (a repository is where you store your snapshots).
2. The ability to add a 2 factor authentication (evrPanel secrets are used for that).
3. The ability to add/adjust replication locations.
4. Encrypted password storage instead of plaintext (Decrypted with secrets into runtime)

Nativelly you can install wordpress and phpbb during the installation wizard. You also have the ability to create your own zip files with installation instructions, a dummy zip is available for download.

The modification done to wordpress is that it fetches the database password from runtime instead of having it visible in plaintext. PHPBB got the same perk. If encryption is used, then the passwords are decrypted during container start and loaded into runtime. 

During installation you have the convenience of setting up backups, right now the native sql backup method is sqldump. Kopia would snapshot the sql backup folder and files, meaning that you need to import the sql database if you move server. 

SSH access exist as-well.

Folders that need to persist: /home /var/www /var/lib/mysql /usr/local/lsws

Secrets used: key and 2fa

Deploy with everlomp/everlomp:latest--vol1--var___www--vol2--var___lib___mysql--vol3--usr___local___lsws--sec1--2fa

Get support and get involved at https://discord.gg/DAQszjKEBV (Evernode Community Discord)

Dockerhub image: everlomp/everlomp:latest

Comment:
This code has no guarantee, it's just a vision of how everlomp should look like. I hope someone forks this and make it better. For example, the decryption key secret could be loaded with less read permissions. Working on migration between servers could be worth while, it would be interesting to work on easily restoreable database snapshots.
