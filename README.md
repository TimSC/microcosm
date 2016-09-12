microcosm
=========

OSM API implemented in PHP. More documentation at: https://wiki.openstreetmap.org/wiki/Microcosm

Install on nginx
================

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

Random notes
============

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

