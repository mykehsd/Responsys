<?php

namespace Mykehsd\Responsys;

use SoapFault,
	SoapVar,
	SoapHeader;

use stdClass;
use Exception;

class Client
{

	private $wsdl = array(
		'interact4' => 'https://ws4.responsys.net/webservices57/services/ResponsysWS57?wsdl',
		'interact3' => 'https://ws3.responsys.net/webservices57/services/ResponsysWS57?wsdl',
		'interact2' => 'https://ws1.responsys.net/webservices57/services/ResponsysWS57?wsdl'
	);

	/** 
	 * @string
	 * Responsys session id
	 */
	private $sessionId;

	/**
	 * @\SoapClient
	 * PHP Soapclient connection
	 */
	private $client;

	// Keep track of our last folder used so we don't repeat it.
	private $lastFolder = false;

	// Keep track of our last document used so we don't repeat it.
	private $lastDocument = false;

	// Keep track of our last data source used so we don't repeat it.
	private $lastDatasource = false;

	/** 
	 * Construct automatically initalizes a session 
	 */
	public function __construct($user, $password, $pod="interact4")
	{
		if (!isset($this->wsdl[$pod]))
			throw new ResponsysException ("Could not find that specified pod");

		// Create our SOAP client
		$this->client = new \SoapClient($this->wsdl[$pod], array('trace' => true, 'exception' => true));

		if (false === $this->createSession($user, $password))
		{
			throw new ResponsysException ("Login error");
		}

	}

	/** 
	 * Destruct sessions
	 */
	public function __destruct()
	{
		$this->endSession();
		unset($this->client);
	}

	/** 
	 * Create a login session with a username and password.
	 * @string Login
	 * @string Password
	 * @return boolean
	 */
	private function createSession($user, $password)
	{
		$args = new stdClass();
		$args->username = $user;
		$args->password = $password;

		try {
			$result = $this->client->login ($args);
			$this->sessionId = $result->result->sessionId;

			$session_header = new SoapVar(
				array(
					'sessionId' => new SoapVar($this->sessionId, XSD_STRING, NULL, NULL, NULL, 'ws.rsys.com'),
				), SOAP_ENC_OBJECT);

			$header = new SoapHeader('ws.rsys.com', 'SessionHeader', $session_header);
			$this->client->__setSoapHeaders(array($header));
			$jsession_id = $this->client->_cookies["JSESSIONID"][0];
			$this->client->__setCookie("JSESSIONID", $jsession_id);			
			return true;
		} catch (SoapFault $e)
		{
			return false;
		}
	}

	/**
	 * endSession
	 * Logout of session
	 */
	public function endSession()
	{
		$this->client->logout();
	}

/**
 * Form submission
 */
	public function submitForm($form, $data)
	{
		if (!is_array($data))
			throw new ResponsysException("Data provided is not in a valid format");

		$args = new stdClass();
		$args->formName = $form;

		$args->formData = array();
		foreach ($data as $key => $value)
		{
			$formData = new stdClass();
			$formData->name = $key;
			$formData->value = $value;
			$args->formData[] = $formData;
		}

		try {
			$this->client->triggerFormRules($args);
			return true;
		} catch (SoapFault $e) {
			throw new ResponsysException("Could not submit form");
		}
	}
/** 
 * Folder actions 
*/

	/**
	 * List all folders.
	 *
	 * @return array
	 *   An array of folder names.
	*/
	public function getFolders() {
		$folders = array();
		$results = $this->client->listFolders();

		foreach ($results->result as $result) {
			$folders[] = $result;
		}
		return $folders;
	}

	/**
	 * Use the createFolder call to create a new empty folder on Responsys.
	 *
	 * @param string $folder
	 *   The name of the folder to create
	 *
	 * @return $this
	*/
	public function createFolder($folder) {
		$args = new stdClass();
		$args->folderName = $folder;

	    try {
			$result = $this->client->createFolder($args);
			$this->lastFolder = $folder;

		} catch (SoapFault $e) {
      		$detail = $e->detail->FaultException->exceptionMessage;
			if ($detail != "Folder already exists: " . $folder)
				throw new ResponsysException("Folder name already exists or is invalid");

			$this->lastFolder = $folder;
		}

		return $this;
  }

/** 
 * Campaign management actions
 */
	public function createCampaign($campaignName, $displayName, $fromEmail, $replyToEmail, $subject, $distributionListName, $documentName, $folder = false)
	{
		if (false === $folder && false !== $this->lastFolder)
			$folder = $this->lastFolder;

		if (false === $folder)
			throw new ResponsysException("Please define a folder");

		$args = new stdClass();
		$args->folderName = $folder;
		$args->campaignType = 'real-time';

		$this->client->createCampaign($args);

		$args = new stdClass();
		$args->parentFolder = $folder;
		$args->campaignName = $campaignName;
		$args->displayName = $displayName;
		$args->fromEmail = $fromEmail;
		$args->replyToEmail = $replyToEmail;
		$args->subject = $subject;
		$args->openSense = true;
		$args->skipDuplicates = true;

		$distributionList = new stdClass();
		$distributionList->folderName = $folder;
		$distributionList->objectName = $distributionListName;
		$args->distributionList = $distributionList;

		$document = new stdClass();
		$document->folderName = $folder;
		$document->documentName = $documentName;
		$document->documentType = 'HTML';
		$args->document = $document;

		$this->client->setCampaignProperties($args);
	}


/**
 * Data Management actions
 */
	public function createDataSource($tableName, $data, $folder = false )
	{
		if (false === $folder && false !== $this->lastFolder)
			$folder = $this->lastFolder;

		if (false === $folder)
			throw new ResponsysException("Please define a folder");

		if (false === in_array('EMAIL_ADDRESS', array_keys($data)))
			throw new ResponsysException("You must include a \"EMAIL_ADDRESS\" key in your data source");

		$args = new stdClass();

		// Create our CSV data by reusing the builtin php csv methods
        $fp = fopen('php://temp', 'r+');

        fputcsv($fp, array_keys($data[0]), ",", "\"");
        foreach ($data as $row)
        	fputcsv($fp, $row, ",", "\"");
        rewind($fp);
        $csvData = fread($fp, 990000);
        fclose($fp);

        $args->properties = new stdClass();
		$args->properties->csvFileData = $csvData;
		$args->properties->csvFileName = "$tableName-$folder";
		$args->properties->tableName = $tableName;
		$args->properties->folderName= $folder;

		// Define the CSV field names
		$keys = array_keys($data[0]);
		$fields = array();
		$mapping = array();

		foreach ($keys as $key)
		{
			$map = new stdClass();
			$map->fieldName = $key;
			$map->fieldType = "STR255";
			$mapping[] = $map;
		}

		$args->properties->fields = $mapping;
		$args->properties->delimitedBy = 'COMMA';
		$args->properties->enclosedBy = 'DOUBLE_QUOTE';
		$args->properties->emailAddressField = 'EMAIL_ADDRESS';

		try {
			$result = $this->client->createDataSource($args);
			$this->lastDatasource = $tableName;
		} catch (SoapFault $e) {
			throw new ResponsysException ("Error creating data source");
		}

		return $this;
	}

	public function cleanup()
	{
		try {
			// Delete previous data source
			$args = new stdClass();
			$args->folderName = $this->lastFolder;
			$args->dataSourceName = $this->lastDatasource;
			$this->client->deleteDataSource($args);

			$args = new stdClass();
			$args->folderName = $this->lastFolder;
			$args->documentName = $this->lastDocument;
			$this->client->removeDocument($args);

			$args = new stdClass();
			$args->folderName = $this->lastFolder;
			$this->client->deleteFolder($args);
		} catch (SoapFault $e) {
			throw new ResponsysException ("We had problems cleaning up after ourselves.");
		}
		return $this;
	}
}