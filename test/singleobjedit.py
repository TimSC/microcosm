import sys
sys.path.append( "." )
from urlutil import *

baseurl = "http://localhost/m/microcosm.php"
#baseurl = "http://www.openstreetmap.org/api"
userpass = "mapping@sheerman-chase:test123"

#Create a changeset
createChangeset = "<?xml version='1.0' encoding='UTF-8'?>\n" +\
"<osm version='0.6' generator='JOSM'>\n" +\
"  <changeset  id='0' open='false'>\n" +\
"    <tag k='comment' v='python test function' />\n" +\
"    <tag k='created_by' v='JOSM/1.5 (3592 en_GB)' />\n" +\
"  </changeset>\n" +\
"</osm>\n"

response = Put(baseurl+"/0.6/changeset/create",createChangeset,userpass)
print response
cid = int(response[0])
if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": exit()

#Create a node
createNode = "<?xml version='1.0' encoding='UTF-8'?>\n" +\
"<osm version='0.6' generator='JOSM'>\n" +\
"  <node id='-289' visible='true' changeset='"+str(cid)+"' lat='51.25022331526812' lon='-0.6042092878597837' />\n" +\
"</osm>\n"

response = Put(baseurl+"/0.6/node/create",createNode,userpass)
print response
if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": exit()
nodeId = int(response[0])

#Close the changeset
response = Put(baseurl+"/0.6/changeset/"+str(cid)+"/close","",userpass)
print response
if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": exit()

#Open another changeset
response = Put(baseurl+"/0.6/changeset/create",createChangeset,userpass)
print response
cid = int(response[0])
if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": exit()

#Delete test node

deleteNode = "<?xml version='1.0' encoding='UTF-8'?>\n" +\
"<osm version='0.6' generator='JOSM'>\n" +\
"  <node id='"+str(nodeId)+"' changeset='"+str(cid)+"' version='1' />\n" +\
"</osm>\n"
response = Delete(baseurl+"/0.6/node/"+str(nodeId),deleteNode,userpass)
print response
if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": exit()

#Close changeset
response = Put(baseurl+"/0.6/changeset/"+str(cid)+"/close","",userpass)
print response
if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": exit()

