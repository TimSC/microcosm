<?php

include_once ('osmtypes.php');

define("READ_FILE_PAGE_SIZE",8192);

function ExtractBz2($file,&$callback)
{
	$bz = bzopen($file, "r") or die("Couldn't open $file");

	while (!feof($bz)) {
		$callback->Process(bzread($bz, READ_FILE_PAGE_SIZE));
	}
	bzclose($bz);
	$callback->Process("",1);
}

function ExtractOsmXml($file,&$callback)
{
	$handle = fopen($file, "rt");
	while (!feof($handle)) {
		$callback->Process(fread($handle, READ_FILE_PAGE_SIZE));
	}
	fclose($handle);
	$callback->Process("",1);
}

function ExtractGz($file,&$callback)
{
	$bz = gzopen($file, "r") or die("Couldn't open $file");

	while (!feof($bz)) {
		$callback->Process(gzread($bz, READ_FILE_PAGE_SIZE));
	}
	gzclose($bz);
	$callback->Process("",1);
}

class ExtractToXml
{
	var $size = 0;
	var $depth = 0;
	var $verbose = 1;

	function startElement($parser, $name, $attrs) 
	{
		if($this->verbose)
		{
		for ($i = 0; $i < $this->depth; $i++) 
			echo "  ";
		echo "$name\n";
		}
		$this->depth++;
	}

	function endElement($parser, $name) 
	{
		$this->depth--;
	}

	function __construct()
	{
		$this->xml_parser = xml_parser_create();
		xml_set_element_handler($this->xml_parser, 
			array ( &$this, 'startElement' ), 
			array ( &$this, 'endElement' ));
	}

	function __destruct()
	{
		xml_parser_free($this->xml_parser);
	}

	function Process($data, $end=0)
	{
		//print_r($data);
		$this->size += strlen($data);	
		if (!xml_parse($this->xml_parser, $data, $end))
		{
			die(sprintf("XML error: %s at line %d\n",
				xml_error_string(xml_get_error_code($this->xml_parser)),
				xml_get_current_line_number($this->xml_parser)));
		}
	}
}

class ExtractGetSize
{
	var $size = 0;
	function Process($data, $end=0)
	{
		$this->size += strlen($data);	
	}
}


$osmAttributesTypes = array('id'=>'int','action'=>'string',
	'lat'=>'float','lon'=>'float','changeset'=>'int',
	'user'=>'string','uid'=>'int','visible'=>'boolean',
	'timestamp'=>'timestamp','version'=>'int');

class OsmTypesStream extends ExtractToXml
{
	var $currentObj = null;
	public $callback = null;

	function startElement($parser, $name, $attrs) 
	{

		if($this->depth == 1)
		{
			//echo $name;
			//print_r($attrs);
			$this->currentObj = OsmElementFactory($name);

			if(is_object($this->currentObj))
			{
			//Copy attributes
			global $osmAttributesTypes;

			foreach($attrs as $k => $v)
			{
				try
				{
				if($osmAttributesTypes[strtolower($k)])
					$this->currentObj->attr[strtolower($k)] = ValidateValue($v,$osmAttributesTypes[strtolower($k)]);
				else
					$this->currentObj->attr[strtolower($k)] = ValidateValue($v,'string');
				}
				catch (Exception $e) 
				{
					echo 'Caught exception: ',  ($e->getMessage()), "\n";
				}
			}
			}
		}

		if($this->depth == 2 and is_object($this->currentObj))
		{
			//echo $name;
			//print_r($attrs);

			if(strcasecmp($name,"tag")==0)
			{
				//print_r($attrs);
				try
				{
				$this->currentObj->tags[ValidateValue($attrs['K'],'string')] = ValidateValue($attrs['V'],'string');
				}
				catch (Exception $e) 
				{
					echo 'Caught exception: ',  ($e->getMessage()), "\n";
				}
			}

			if(strcasecmp($name,"nd")==0)
			{
				//print_r($attrs);
				$ref = (int)$attrs['REF'];
				array_push($this->currentObj->members,array("node",$ref,null));
			}

			if(strcasecmp($name,"member")==0)
			{
				//print_r($attrs);
				$ref = (int)$attrs['REF'];
				$type = ValidateValue($attrs['TYPE'],'string');
				$role = ValidateValue($attrs['ROLE'],'string');
				array_push($this->currentObj->members,array($type,$ref,$role));
			}
		}

		$this->depth++;
	}

	function endElement($parser, $name) 
	{
		$this->depth--;

		if($this->depth == 1)
		{
			//Object is complete
			if(is_object($this->currentObj) and is_null($this->callback))
			print_r($this->currentObj->ToXmlString());

			//Send finished object to callback func
			if(!is_null($this->callback) and !is_null($this->currentObj))
				call_user_func($this->callback, $this->currentObj, $this->size);

			$this->currentObj = null;
		}
	}


}

?>
