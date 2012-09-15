import pycurl
import os.path
import StringIO

#baseurl = "http://localhost/m/microcosm.php"
#baseurl = "http://api06.dev.openstreetmap.org/api"
#baseurl = "http://www.openstreetmap.org/api"

def Put(url, stringIn, userpass = None):
	p = StringIO.StringIO(stringIn)
	
	c = pycurl.Curl()
	c.setopt(pycurl.URL, url)
	c.setopt(pycurl.PUT, 1)
	c.setopt(pycurl.READFUNCTION, p.read)
	if userpass is not None: c.setopt(pycurl.USERPWD, userpass)
	
	#header = ["Content-Length: "+str(len(stringIn))]
	#c.setopt(pycurl.HTTPHEADER, header)

	b = StringIO.StringIO()
	c.setopt(pycurl.WRITEFUNCTION, b.write)
	h = StringIO.StringIO()
	c.setopt(pycurl.HEADERFUNCTION, h.write)  

	c.perform()
	return b.getvalue(), h.getvalue()

def Delete(url, stringIn, userpass = None):
	p = StringIO.StringIO(stringIn)
	
	c = pycurl.Curl()
	c.setopt(pycurl.URL, url)
	c.setopt(pycurl.CUSTOMREQUEST, "DELETE")
	c.setopt(pycurl.READFUNCTION, p.read)
	if userpass is not None: c.setopt(pycurl.USERPWD, userpass)
	
	b = StringIO.StringIO()
	c.setopt(pycurl.WRITEFUNCTION, b.write)
	h = StringIO.StringIO()
	c.setopt(pycurl.HEADERFUNCTION, h.write)  

	c.perform()
	return b.getvalue(), h.getvalue()

def Post(url, stringIn, userpass = None):
	p = StringIO.StringIO(stringIn)
	
	c = pycurl.Curl()
	c.setopt(pycurl.URL, url)
	c.setopt(pycurl.POST, 1)
	c.setopt(pycurl.POSTFIELDS, stringIn)
	if userpass is not None: c.setopt(pycurl.USERPWD, userpass)
	
	b = StringIO.StringIO()
	c.setopt(pycurl.WRITEFUNCTION, b.write)
	h = StringIO.StringIO()
	c.setopt(pycurl.HEADERFUNCTION, h.write)  

	c.perform()
	return b.getvalue(), h.getvalue()

def Get(url, userpass = None):
	c = pycurl.Curl()
	c.setopt(pycurl.URL, url)
	if userpass is not None: c.setopt(pycurl.USERPWD, userpass)

	b = StringIO.StringIO()
	c.setopt(pycurl.WRITEFUNCTION, b.write)
	h = StringIO.StringIO()
	c.setopt(pycurl.HEADERFUNCTION, h.write)  

	c.perform()
	return b.getvalue(), h.getvalue()

def HeaderToDict(header):
	hs = header.split("\n")
	hsd = dict()
	for line in hs:
		if line.find(":")==-1: continue
		linesp = line.split(":",1)
		hsd[linesp[0]] = linesp[1].lstrip(" ").rstrip("\r")
	return hsd

def HeaderResponseCode(header):
	prefix = "HTTP/1.1 "
	hs = header.split("\n")
	for line in hs:
		#print line
		line = line.rstrip("\r")
		if line[:len(prefix)] == prefix:
			if line == "HTTP/1.1 100 Continue": continue
			return line
	return None
	#return hs[0].rstrip("\r")
	

