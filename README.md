microcosm
=========

OSM API implemented in PHP

config.php contains :

      define("MYSQL_DB_NAME","db_map");
      define("MYSQL_SERVER","localhost");
      define("MYSQL_USER","map_map");
      define("MYSQL_PASSWORD","4yuy34udm"); #Keeping this as default is a bad idea

So create the following :

     mysql> create database db_map;
     mysql> grant all on db_map.* to map_map identified by '4yuy34udm';


Test : 
     info.php


Export data:
export.php

Import data: 
Usage: php import.php -i data.osm [--dontnuke] [--dontlock]


Setup permissions :
  chown www-data:www-data *.txt
  sudo chown www-data:www-data *.txt
  sudo chown www-data:www-data db.lock 
  sudo chown www-data:www-data log.txt
  touch log.txt
  sudo chown www-data:www-data log.txt


Setup apache :

apachemain.conf
