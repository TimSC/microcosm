import sys
sys.path.append( "." )
from urlutil import *

def TestSingleObjectEditing(userpass, verbose=0):

	#Create a changeset
	createChangeset = "<?xml version='1.0' encoding='UTF-8'?>\n" +\
	"<osm version='0.6' generator='JOSM'>\n" +\
	"  <changeset  id='0' open='false'>\n" +\
	"    <tag k='comment' v='python test function' />\n" +\
	"    <tag k='created_by' v='JOSM/1.5 (3592 en_GB)' />\n" +\
	"  </changeset>\n" +\
	"</osm>\n"

	response = Put(baseurl+"/0.6/changeset/create",createChangeset,userpass)
	if verbose: print response
	cid = int(response[0])
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error creating changeset")

	lat = 51.25022331526812
	lon = -0.6042092878597837

	#Create a node
	createNode = "<?xml version='1.0' encoding='UTF-8'?>\n" +\
	"<osm version='0.6' generator='JOSM'>\n" +\
	"  <node id='-289' changeset='"+str(cid)+"' lat='"+str(lat)+"' lon='"+str(lon)+"' />\n" +\
	"</osm>\n"
	response = Put(baseurl+"/0.6/node/create",createNode,userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error creating node")
	nodeId = int(response[0])

	#Read back node
	response = Get(baseurl+"/0.6/node/"+str(nodeId))
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error reading back node")

	#Create another node
	createNode = "<?xml version='1.0' encoding='UTF-8'?>\n"+\
	"<osm version='0.6' generator='JOSM'>\n"+\
	"  <node id='-2008' changeset='"+str(cid)+"' lat='51.2419166618214' lon='-0.5910182209303836' />\n"+\
	"</osm>\n"
	response = Put(baseurl+"/0.6/node/create",createNode,userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error creating node")
	nodeId2 = int(response[0])

	#Create a way
	createWay = "<?xml version='1.0' encoding='UTF-8'?>\n"+\
	"<osm version='0.6' generator='JOSM'>\n"+\
	"  <way id='-2010' changeset='"+str(cid)+"'>\n"+\
	"    <nd ref='"+str(nodeId)+"' />\n"+\
	"    <nd ref='"+str(nodeId2)+"' />\n"+\
	"  </way>\n"+\
	"</osm>\n"
	response = Put(baseurl+"/0.6/way/create",createWay,userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error creating node")
	wayId = int(response[0])

	#Close the changeset
	response = Put(baseurl+"/0.6/changeset/"+str(cid)+"/close","",userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error closing changeset")

	#Open another changeset
	response = Put(baseurl+"/0.6/changeset/create",createChangeset,userpass)
	if verbose: print response
	cid = int(response[0])
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error creating changset")

	#Modify test node
	modifyNode = '<osmChange version="0.6" generator="JOSM">'+"\n"+\
	"<modify>\n"+\
	"  <node id='"+str(nodeId)+"' version='1' changeset='"+str(cid)+"' lat='"+str(lat)+"' lon='"+str(lon)+"' />\n"+\
	"</modify>\n"+\
	"</osmChange>\n"
	response = Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",modifyNode,userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error deleting node")

	#Delete test node
	#It seems that pycurl doesn't allow the delete method to upload content. Use the multi object API insted.
	#deleteNode = "<?xml version='1.0' encoding='UTF-8'?>\n" +\
	#"<osm version='0.6' generator='JOSM'>\n" +\
	#"  <node id='"+str(nodeId)+"' changeset='"+str(cid)+"' version='2' />\n" +\
	#"</osm>\n"
	#response = Delete(baseurl+"/0.6/node/"+str(nodeId),deleteNode,userpass)
	#if verbose: print response
	#if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error deleting node")

	deleteWay = '<osmChange version="0.6" generator="JOSM">'+"\n"+\
	"<delete>\n"+\
	"  <way id='"+str(wayId)+"' version='1' changeset='"+str(cid)+"'/>\n"+\
	"</delete>\n"+\
	"</osmChange>\n"

	response = Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",deleteWay,userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error deleting node")

	deleteNode = '<osmChange version="0.6" generator="JOSM">' +\
	"<delete>\n" +\
	"  <node id='"+str(nodeId)+"' version='2' "+\
	"changeset='"+str(cid)+"' lat='"+str(lat)+"'  lon='"+str(lon)+"' />\n"+\
	"</delete>\n"+\
	"</osmChange>\n"
	response = Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",deleteNode,userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error deleting node")

	deleteNode = '<osmChange version="0.6" generator="JOSM">' +\
	"<delete>\n" +\
	"  <node id='"+str(nodeId2)+"' version='1' "+\
	"changeset='"+str(cid)+"' lat='"+str(lat)+"'  lon='"+str(lon)+"' />\n"+\
	"</delete>\n"+\
	"</osmChange>\n"
	response = Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",deleteNode,userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error deleting node")

	#Delete a non-existant node
	#nonExistId = 999999999999999
	#deleteNode = '<osmChange version="0.6" generator="JOSM">' +\
	#"<delete>\n" +\
	#"  <node id='"+str(nonExistId)+"' version='1' "+\
	#"changeset='"+str(cid)+"' lat='"+str(lat)+"'  lon='"+str(lon)+"' />\n"+\
	#"</delete>\n"+\
	#"</osmChange>\n"
	#response = Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",deleteNode,userpass)
	#if verbose: print response
	#if HeaderResponseCode(response[1]) != "HTTP/1.1 409 Conflict": return (0,"Error deleting node")

	#Close changeset
	response = Put(baseurl+"/0.6/changeset/"+str(cid)+"/close","",userpass)
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 200 OK": return (0,"Error closing changeset")

	#Attempt to read deleted node
	response = Get(baseurl+"/0.6/node/"+str(nodeId))
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 410 Gone": 
		return (0,"Error deleted node had wrong header code")
	if response[0] != "":#"The node with the id "+str(nodeId)+" has already been deleted":
		return (0,"Error reading deleted node had wrong message")

	#Check nodes have been deleted OK
	ret = ReadDeletedNode(nodeId)
	if ret[0] == 0: return ret
	ret = ReadDeletedNode(nodeId2)
	if ret[0] == 0: return ret

	return (1,"OK")

def ReadDeletedNode(nodeId,verbose=1):
	#Attempt to read deleted node
	response = Get(baseurl+"/0.6/node/"+str(nodeId))
	if verbose: print response
	if HeaderResponseCode(response[1]) != "HTTP/1.1 410 Gone": 
		return (0,"Error deleted node had wrong header code")
	if response[0] != "":
		return (0,"Error reading deleted node had wrong message")

	return (1,"OK")




baseurl = "http://localhost/m/microcosm.php"
#baseurl = "http://www.sheerman-chase.org.uk:81/m/microcosm.php"
#baseurl = "http://www.openstreetmap.org/api"
username = raw_input("Username:")
password = raw_input("Password:")
userpass = username+":"+password

print TestSingleObjectEditing(userpass,1)
#print ReadDeletedNode(981182860,1)

