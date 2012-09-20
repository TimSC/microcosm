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

