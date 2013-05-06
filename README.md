Responsys PHP Api Client
========================

This library supports most methods of the responsys api.  You can:
- Create folders
- Submit forms
- Create new campaigns 
- Manage data sources


State
-----
This is considered beta state but production ready.  

Installation
------------
Installation is done via the wonderful composer package.  Once composer is installed 
``` 
composer require mykehsd/responsys
```

Usage
-----
The first step is to initiate your client object with you login information
```
$client = new Client('username', 'password');

#Create folders
$client->createFolder("My New Folder");

#Create Datasource
$client->createDataSource("ds_name", array(
	"EMAIL_ADDRESS" => "test@example.com",
), 'My New Folder');

#Trigger form submission
$client->submitForm("My API Form", array(
	"NAME" => "John Smith",
	"EMAIL" => "test@example.com"
));
```

Notes
-----
All methods can be chained together to create a simple interface
```
$client
	->createFolder("New Folder"
	->submitForm("My API Form", array(
        "NAME" => "John Smith",
        "EMAIL" => "test@example.com"
));
