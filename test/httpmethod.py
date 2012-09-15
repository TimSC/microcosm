
import sys
sys.path.append( "." )
from urlutil import *

baseurl = "http://localhost/m/microcosm.php"
#baseurl = "http://www.openstreetmap.org/api"
countErr = 0

def CheckUrlMethod(url, tryMethod, correctMethod):

	if tryMethod == "GET": response = Get(url)
	if tryMethod == "POST": response = Post(url,"")
	if tryMethod == "PUT": response = Put(url,"")

	if HeaderResponseCode(response[1]) != "HTTP/1.1 405 Method Not Allowed":
		print "Wrong server response for invalid method"

	if "Error" not in HeaderToDict(response[1]) or \
		HeaderToDict(response[1])['Error'] != \
		"Only the "+correctMethod+" method is supported for map requests.":
		print "Error message for wrong http method incorrect"

url = baseurl+"/0.6/map?bbox=0.636129,51.207553,-0.5081755,51.2828722"
CheckUrlMethod(url,"PUT","GET")
CheckUrlMethod(url,"POST","GET")


