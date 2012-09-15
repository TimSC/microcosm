
import sys
sys.path.append( "." )
from urlutil import *

baseurl = "http://localhost/m/microcosm.php"
#baseurl = "http://www.openstreetmap.org/api"
countErr = 0

def CheckInvalidBboxResponse(serverResponse):
	#print (serverResponse[1])
	countErr = 0
	if HeaderResponseCode(serverResponse[1]) != 'HTTP/1.1 400 Bad Request':
		print "Invalid bbox resulted in wrong server response code: "+HeaderResponseCode(serverResponse[1])
		countErr = countErr + 1

	if 'Error' not in HeaderToDict(serverResponse[1]) or \
			HeaderToDict(serverResponse[1])['Error'] != \
			'The latitudes must be between -90 and 90, longitudes between '+\
			'-180 and 180 and the minima must be less than the maxima.': 
		print "Error incorrect for invalid bbox"
		countErr = countErr + 1
	return countErr

countErr = countErr + CheckInvalidBboxResponse(Get(baseurl+"/0.6/map?bbox=0.636129,51.207553,-0.5081755,51.2828722"))
countErr = countErr + CheckInvalidBboxResponse(Get(baseurl+"/0.6/map?bbox=-0.636129,91.207553,-0.5081755,91.2828722"))
countErr = countErr + CheckInvalidBboxResponse(Get(baseurl+"/0.6/map?bbox=-180.636129,91.207553,-180.5081755,91.2828722"))

serverResponse = Get(baseurl+"/0.6/map?bbox=-180.0,-90.0,180.0,90.0")
#print serverResponse

if HeaderResponseCode(serverResponse[1]) != 'HTTP/1.1 400 Bad Request':
	print "Over size bbox resulted in wrong server response code:"+HeaderResponseCode(serverResponse[1])
	countErr = countErr + 1

errorStartStr = "The maximum bbox size is "
errorEndStr = ", and your request was too large. Either request a smaller area, or use planet.osm"
badError = 0

if 'Error' not in HeaderToDict(serverResponse[1]) or HeaderToDict(serverResponse[1])['Error'][:len(errorStartStr)] != errorStartStr: badError = 1
if 'Error' not in HeaderToDict(serverResponse[1]) or HeaderToDict(serverResponse[1])['Error'][-len(errorEndStr):] != errorEndStr: badError = 1
if badError:
	print "Error incorrect for over size bbox"
	countErr = countErr + 1


#return countErr

