microcosm
=========

OSM API implemented in PHP. More documentation at: https://wiki.openstreetmap.org/wiki/Microcosm

The backend data base is sqlite. There is experimental support for PostGIS (postgreSQL) and MySQL.

Install on nginx
----------------

	sudo apt install php php-fpm 
	sudo apt install nginx

Edit /etc/nginx/sites-available/default

Add index.php to the "index" section. It should read something like:

	index index.html index.htm index.php;

Uncomment the section so the following is enabled:

	location ~ \.php$ {
		# With php7.0-fpm:
		fastcgi_pass unix:/run/php/php7.0-fpm.sock;
	}

And add the following block for the API, with appropriate paths:

	location ^~ /api {
	   fastcgi_pass unix:/run/php/php7.0-fpm.sock;
	   root   /var/www/html/microcosm/; # Microcosm directory
	   fastcgi_index microcosm.php;
	   include        fastcgi_params;
	   fastcgi_split_path_info ^(\/api)(.*)$;
	   fastcgi_param SCRIPT_FILENAME $document_root/microcosm.php;
	   fastcgi_param PATH_INFO $fastcgi_path_info;
	}

Restart services:

	sudo service php7.0-fpm restart
	sudo service nginx restart

Install PostGIS
---------------

Install PostGIS extensions TODO

Create user and database

    sudo su - postgres
	psql

    CREATE USER microcosm WITH PASSWORD '4yuy34udm';
	CREATE DATABASE db_map;
	GRANT ALL PRIVILEGES ON DATABASE db_map to microcosm;

Get back to your normal user and see if you can connect:

    psql --username=microcosm --password --host=127.0.0.1 --dbname=db_map

If that fails, check your pg_hba.conf for allowed authentication methods and check your error logs: /var/log/postgresql/postgresql-9.5-main.log

Import your data using: https://github.com/TimSC/osm2pgcopy

Random notes
------------

config.php contains :

      define("MYSQL_DB_NAME","db_map");
      define("MYSQL_SERVER","localhost");
      define("MYSQL_USER","map_map");
      define("MYSQL_PASSWORD","4yuy34udm"); #Keeping this as default is a bad idea

So create the following :

     mysql> create database db_map;
     mysql> grant all on db_map.* to map_map identified by '4yuy34udm';

Test with web browser: 
     info.php

Export data:
     export.php

Import data: 
      Usage: php import.php -i data.osm [--dontnuke] [--dontlock]

Setup permissions :
      chown www-data:www-data *.txt
      sudo chown www-data:www-data *.txt
      sudo chown www-data:www-data db.lock 
      touch log.txt
      sudo chown www-data:www-data log.txt

Setup apache: apachemain.conf

