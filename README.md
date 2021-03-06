SimplePO - A Web Based PO File Editor
=====================================


## Why SimplePO?

We grew tired of emailing po files to translators, then having to explain
how to edit the files, and waiting until they returned the translation file.
We created Simple PO so we could just ask our translators to go to a web page
and start translating.


## What is needed to use SimplePO?

- SimplePO requires PHP, including the command line interface.
- Make sure the gettext family of tools is installed on the server.
- Also requires mysql database.


## How to get started?

- Create copy of repo: 

    `composer create-project tacoberu/simplepo:@dev .`

- Create a copy of the file config-sample.php and save as config.php

- Open config.php and add the database username, password, host, and database name

- Next, to install SimplePO, run the following on command line:

    `php simplepo.php --install`

- or to force the installation that will overwrite all tables with conflicting names use the force flag, `--force`, `-f`.

- At this point you will need to import the PO file:

    `php simplepo.php -n "Catalogue name" -i "Input filename.po"`

    `php simplepo.php -n "Français" -i example.po`


Now if you point your web browser to the location where you installed SimplePO
you will see a list of all of the Catalogues.

Translators will now have a simple web interface to use to add translations.

- To export all translations back to a PO file:

    `php simplepo.php -n "Catalogue name" -o "Output filename.po"`

- To update from a PO or POT file (existent translation will not be erased):

    `php simplepo.php -n "Catalogue name" -i "Input filename.po"`

