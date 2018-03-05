# amazon-marketing-api-class
A sample Laravel class in connecting to Amazon Marketing API using PHP

To use the class, simply include it in your controller or class, assuming the class is saved in the App/Includes folder

use App\Includes\AmazonAPI

Constructor code for the calling class/controller

Here is a test call to the class.

// $code - Amazon user code after logging in to the Amazon interface
// $redirect_uri - your custom function that fetches the Amazon user's code
// client_id - client ID from Amazon
// client_secrect - client secret from Amazon

$this->amazon_api->getAccessToken('authorization_code',
                               $code,
                               $redirect_uri = URL::to('/').'/amazon/get-code',
                               $this->client_id,
                               $this->client_secret); 
                               
                   
                               


