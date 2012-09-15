import pycurl
import os.path
import StringIO

f = open("test.xml")
fs = os.path.getsize("test.xml")
baseurl = "http://localhost/m/microcosm.php"
#baseurl = "http://api06.dev.openstreetmap.org/api"
#baseurl = "http://www.openstreetmap.org/api"
userpass = "mapping@sheerman-chase.org.uk:test123"
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
cid = 488

#cid = 177
#modifydiff = "<?xml version='1.0' encoding='UTF-8'?><osm version='0.6' generator='JOSM'><node id='328' timestamp='2010-10-05T11:37:38Z' uid='6809' user='TimSC' visible='true' version='1' changeset='173' lat='51.285628032513536' lon='-0.5964144741402374' /></osm>"

#print Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",open("test.xml").read())
#print Put(baseurl+"/0.6/node/create",open("test.xml").read())

#print Put(baseurl+"/0.6/node/328",open("test.xml").read())
print Post(baseurl+"/0.6/changeset/"+str(cid)+"/upload",open("test.xml").read())
#print Post(baseurl+"/0.6/changeset/"+str(cid)+"/expand_bbox",open("test.xml").read())
#print Put(baseurl+"/0.6/changeset/"+str(cid),open("test.xml").read())
#print Put(baseurl+"/0.6/changeset/create",open("test.xml").read())

#print Put(baseurl+"/0.6/changeset/"+str(cid)+"/close","")
#print Put(baseurl+"/0.6/user/perferences",open("test.xml").read())
#print Put(baseurl+"/0.6/user/perferences/somek'ey2","somevalue")

