from urlutil import *


baseurl = "http://www.sheerman-chase.org.uk:81/m/microcosm.php/0.6/changeset/create"
#baseurl = "http://www.sheerman-chase.org.uk:81/m/microcosm.php/0.6/user/details"

username = raw_input("Username:")
password = raw_input("Password:")
userpass = username+":"+password

createChangeset = "<?xml version='1.0' encoding='UTF-8'?>\n" +\
"<osm version='0.6' generator='JOSM'>\n" +\
"  <changeset  id='0' open='false'>\n" +\
"    <tag k='comment' v='python test function' />\n" +\
"    <tag k='created_by' v='JOSM/1.5 (3592 en_GB)' />\n" +\
"  </changeset>\n" +\
"</osm>\n"

print Put(baseurl+"", createChangeset, userpass)
#print Get(baseurl+"", userpass)



