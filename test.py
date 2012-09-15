import pycurl
import os.path
import StringIO

f = open("test.xml")
fs = os.path.getsize("test.xml")
baseurl = "http://localhost/m/microcosm.php"
#baseurl = "http://api06.dev.openstreetmap.org/api"
#baseurl = "http://www.openstreetmap.org/api"
userpass = "mapping@sheerman-chase.org.uk:test"
#userpass = "jeff@sheerman-chase.org.uk:test"
#userpass = "bob:test"

def Put(url, stringIn):
	p = StringIO.StringIO(stringIn)
	
	c = pycurl.Curl()
	c.setopt(pycurl.URL, url)
	c.setopt(pycurl.PUT, 1)
	#c.setopt(pycurl.READDATA, fi)
	#c.setopt(pycurl.INFILESIZE, int(filesize))
	c.setopt(pycurl.READFUNCTION, p.read)
	c.setopt(pycurl.USERPWD, userpass)
	
	
	b = StringIO.StringIO()
	c.setopt(pycurl.WRITEFUNCTION, b.write)
	h = StringIO.StringIO()
	c.setopt(pycurl.HEADERFUNCTION, h.write)  

	c.perform()
	return b.getvalue(), h.getvalue()

def Post(url, stringIn):
	p = StringIO.StringIO(stringIn)
	
	c = pycurl.Curl()
	c.setopt(pycurl.URL, url)
	c.setopt(pycurl.POST, 1)
	#c.setopt(pycurl.READFUNCTION, p.read)
	c.setopt(pycurl.POSTFIELDS, stringIn)
	c.setopt(pycurl.USERPWD, userpass) 
	
	b = StringIO.StringIO()
	c.setopt(pycurl.WRITEFUNCTION, b.write)
	h = StringIO.StringIO()
	c.setopt(pycurl.HEADERFUNCTION, h.write)  

	c.perform()
	return b.getvalue(), h.getvalue()

createchangeset = "<?xml version='1.0' encoding='UTF-8'?>\n<osm version='0.6' generator='PythonTest'>\n<changeset  id='0' open='false'>\n"\
	+ "<tag k='comment' v='local_knowledge' />\n<tag k='created_by' v='PythonTest' />\n</changeset>\n</osm>"

#cid = int(Put(baseurl+"/0.6/changeset/create",createchangeset)[0])
#print cid



#cid = 6108794
cid = 333
#creatediff = "<osmChange version=\"0.6\" generator=\"PythonTest\">\n<create>\n"\
#	+"<node id='-8836' visible='true' changeset='150' lat='51.28572570520306' lon='-0.5961918364308939' />\n</create>\n</osmChange>"

#modifydiff = "<osmChange version=\"0.6\" generator=\"PythonTest\">\n"\
#	+"<modify>\n<node id='595' action='modify' timestamp='2009-03-01T14:21:58Z' uid='6809' user='TimSC' visible='true' version='1' changeset='"+str(cid)+"' lat='51.285184946434256' lon='-0.5986675534296598'><tag k='name' v='Belvidere'/></node>\n</modify>\n</osmChange>\n"

#modifydiff = "<osmChange version=\"0.6\" generator=\"PythonTest\">\n"
#modifydiff = modifydiff + "<create>\n<node id='-8836' action='create' timestamp='2009-03-01T14:21:58Z' visible='true' changeset='"+str(cid)+"' lat='51.28572570520306' lon='-0.5961918364308939' />\n</create>"
#modifydiff = modifydiff + "<modify>\n<way id='161' action='modify' timestamp='2009-03-01T14:21:58Z' uid='6809' user='TimSC' visible='true' version='1' changeset='"+str(cid)+"' ><tag k='name' v='Belvidere'/><nd ref='-8836'/></way>\n</modify>\n";
#modifydiff = modifydiff + "</osmChange>\n"

modifydiff = "<osmChange version='0.6' generator='JOSM'><create><node id='-1368' visible='true' changeset='231' lat='51.24857021038881' lon='-0.5410488575188592' /><way id='-1369' visible='true' changeset='231'><nd ref='831' /><nd ref='-1368' /></way></create></osmChange>"

#cid = 177
#modifydiff = "<?xml version='1.0' encoding='UTF-8'?><osm version='0.6' generator='JOSM'><node id='328' timestamp='2010-10-05T11:37:38Z' uid='6809' user='TimSC' visible='true' version='1' changeset='173' lat='51.285628032513536' lon='-0.5964144741402374' /></osm>"

print Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",open("test.xml").read())
#print Post(baseurl+"/0.6/node/create",open("test.xml").read())

#print Put(baseurl+"/0.6/node/328",open("test.xml").read())
#print Put(baseurl+"/0.6/changeset/"+str(cid)+"/upload",open("test.xml").read())
#print Put(baseurl+"/0.6/changeset/267",open("test.xml").read())
#print Put(baseurl+"/0.6/changeset/create",open("test.xml").read())

#print Put(baseurl+"/0.6/changeset/"+str(cid)+"/close","")
#print Put(baseurl+"/0.6/user/perferences/somek'ey2","somevalue")

