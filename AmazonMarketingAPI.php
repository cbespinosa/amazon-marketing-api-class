<?php 
namespace App\Includes;

use Illuminate\Support\Facades\Redirect;
use \Exception;
use Session;
use File;
use Config;
use App;
use Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class AmazonMarketingAPI {

	/* 
     * string $url
     *  The default sanbox URL for the requests.
     */
    public $url = 'https://api.amazon.com';

    public $advertising_url = 'https://advertising-api.amazon.com/v1';
    // The default URL for Amazon North America (NA). Covers US, CA and MX marketplaces
    // public $url = 'https://advertising-api.amazon.com';
 	// The default URL for Amazon Europe (EU). Covers UK, FR, IT, ES, DE and IN marketplaces
    // public $url = https://advertising-apieu.amazon.com

    public $token_url = 'https://api.amazon.com/auth/o2/token';

    /*
     * array $attr
     *  The container for all attributes, including token, and response format
     */
    public $attrs = array();
    /*
     * string $format
     *  The default response format
     */
    public $format = 'xml';
    
    /*
     * string $token
     *  The token used for authenticating all requests.
     */
    private $token = NULL;
    
    /*
     * array $valid_params
     *  The container for all valid attributes
     */
    protected $valid_params    = array(
        'format',    'token',    'startdate', 'enddate',
        'number',    'status',    'notstatus'
    );
    
    /*
     * array $valid_formats
     *  The container for all valid formats
     */
    protected $valid_formats = array(
        'xml',    'json',    'html',    'serialize'
    );

    private $client_id;

    private $client_secret;
    
    public $arg = array();

    /*
     * void __construct(string $token)
     *    @param string $token - The unique key used to represent your access to the system.
     *      The constructor of the class, accessed/used when initializing the class.
     */
    public function __construct()
    {

        //Custom configuration - The Client ID is provided by Amazon Marketing API
        $this->client_id = Config::get('amazon-settings.client_id');

        //Custom configuration - The Client ID is provided by Amazon Marketing API
        $this->client_secret = Config::get('amazon-settings.client_secret');
        
        
    }
    
    /*
     *  Get initial Access Token for initial connection
     *  The container for all valid formats
     *  @param string $grant_type
     *  @param string $code
     *  @param string $redirect_uri
     *  @param string $client_id
     *  @param string $client_secret
     */
    public function getAccessToken($grant_type = 'authorization_code',
    								$code,
    								$redirect_uri,
    								$client_id,
    								$client_secret){

    	$ch = curl_init();
    	$fields_string = '';
    	$post_fields = array('grant_type' => urlencode($grant_type), 
    						'code' => urlencode($code), 
    						'redirect_uri' => urlencode($redirect_uri), 
    						'client_id' => urlencode($client_id), 
    						'client_secret' => urlencode($client_secret)
    						);
    	
    	//url-ify the data for the POST
		foreach($post_fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
		rtrim($fields_string, '&');
    	
    	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($ch, CURLOPT_URL,"https://api.amazon.com/auth/o2/token");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS,$fields_string);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$server_output = curl_exec ($ch);

		curl_close ($ch);									

    	return json_decode($server_output);
    }

    /*
     *  Get Refresh Token again from Amazon to keep the connection active if initial token expires
     *  The container for all valid formats
     *  @param string $grant_type
     *  @param string $code
     */
    public function useRefreshToken($grant_type = 'refresh_token',
                                    $refresh_token){

        
        $ch = curl_init();
        $fields_string = '';
        $post_fields = array("grant_type" => urlencode($grant_type), 
                            "refresh_token" => urlencode($refresh_token),
                            'client_id' => urlencode($this->client_id), 
                            'client_secret' => urlencode($this->client_secret)
                            );

        //url-ify the data for the POST
        foreach($post_fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
        $fields_string = rtrim($fields_string, '&');

        // CURL to Amazon to get a new active token or the Refresh token
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_URL,"https://api.amazon.com/auth/o2/token");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,$fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         
      
        $server_output = json_decode(curl_exec($ch));
        
        // Check the return value for errors            
        if(!property_exists($server_output, 'error')){

            Session::put('amazon_code', $server_output->access_token);
            Session::put('amazon_token_type', $server_output->token_type);
            Session::put('amazon_refresh_token', $server_output->refresh_token);
            Session::put('access_token',$server_output->access_token);
            
            $amazon_profile = $this->getAmazonProfile(Session::get('access_token'));
            
            //Save refresh token to user table for reference in the future so you can just activate the connection again via a Refresh token
            Auth::user()->update(array('refresh_token' => $server_output->refresh_token));
            $this->loginAPI();
        }


        curl_close ($ch);  

    }

    /*
     *  Function to log in the user and authenticate their Amazon account via CURL
     */
    public function loginAPI(){
        
        $c = curl_init($this->advertising_url.'/profiles');
        curl_setopt($c, CURLOPT_HTTPHEADER, array('Authorization: bearer ' . Session::get('access_token')));
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
         
        $r = curl_exec($c);
        curl_close($c);
        $amazon_data = json_decode($r);


        if(is_array($amazon_data)){
            Session::put('amazon_user_id',$amazon_data[0]->profileId);
        } else {
            $this->useRefreshToken('refresh_token',Auth::user()->refresh_token);

            if(Session::get('amazon_token_type') != ''){
                $is_access_token = 1;
            }
        }
        

    }

    /*
     *  Get the logged in user's profile
     */
    public function getAmazonProfile($access_token){
    	$amazon_profile_url = $this->url.'/user/profile?access_token='.urlencode($access_token);
   	
    	return $this->curl_pull($amazon_profile_url);
    }

    /*
     *  Get all campaigns for the account
     *  @param string $access_token - live access token or refresh token
     */

    public function getAmazonCampaigns($access_token){
        $amazon_profile_url = $this->advertising_url.'/campaigns';
    
        return $this->amazon_curl($amazon_profile_url,
                                  $access_token,
                                  Session::get('amazon_user_id')
                                  );
    }

    /*
     *  Get a campaign for the account with a specific $campaign_id
     *  @param string $access_token - live access token or refresh token
     *  @param int $campaign_id
     */
    public function getAmazonCampaignsById($access_token,$campaign_id){
        $amazon_profile_url = $this->advertising_url.'/campaigns/'.$campaign_id;
    
        return $this->amazon_curl($amazon_profile_url,
                                  $access_token,
                                  Session::get('amazon_user_id')
                                  );
    }

    /*
     *  Get details for a specific Campaign for the account
     *  @param string $access_token - live access token or refresh token
     *  @param int $campaign_id
     */
    public function getAmazonCampaignDetails($access_token,$campaign_id){
        $amazon_profile_url = $this->advertising_url.'/campaigns/extended/'.$campaign_id;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonAdGroups($access_token){
        $amazon_profile_url = $this->advertising_url.'/adGroups';
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonAdGroupsById($access_token,$adgroup_id){
        $amazon_profile_url = $this->advertising_url.'/adGroups/'.$adgroup_id;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonAdGroupsByCampaignId($access_token,$campaign_id){
        $amazon_profile_url = $this->advertising_url.'/adGroups?campaignIdFilter='.$campaign_id;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonProductAds($access_token){
        $amazon_profile_url = $this->advertising_url.'/productAds';
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonProductAdsById($access_token,$campaign_id,$adgroup_id){
        $amazon_profile_url = $this->advertising_url.'/productAds?campaignIdFilter='.$campaign_id.'&adGroupIdFilter='.$adgroup_id;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }
    
    public function getAmazonAdFilters($access_token, $filterfield){
        $amazon_profile_url = $this->advertising_url.'/keywords/extended?adGroupIdFilter='.$filterfield;
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonAdGroupsKeywords($access_token){
        $amazon_profile_url = $this->advertising_url.'/keywords';
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonAdGroupsKeywordsDetails($access_token,$keyword_id){
        $amazon_profile_url = $this->advertising_url.'/keywords/extended/'.$keyword_id;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonAdGroupsKeywordsById($access_token,$adgroup_id){
        $amazon_profile_url = $this->advertising_url.'/keywords?adGroupIdFilter='.$adgroup_id;

        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonAdGroupsNegativeKeywordsById($access_token,$adgroup_id){
        $amazon_profile_url = $this->advertising_url.'/negativeKeywords?adGroupIdFilter='.$adgroup_id;

        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function deleteAmazonAdGroupsKeywordsById($access_token,$keyword_id){
        $amazon_profile_url = $this->advertising_url.'/keywords/'.$keyword_id;

        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonReports($access_token,$record_type,$post_fields){
        $amazon_profile_url = $this->advertising_url.'/'.$record_type.'/report';
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'),$post_fields);
    }

    public function getAmazonReportInformation($access_token,$report_id){
        $amazon_profile_url = $this->advertising_url.'/reports/'.$report_id;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonReportInformationFile($access_token,$location){
        $amazon_profile_url = $location;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'),array(),true);
    }

    public function getAmazonSnapshots($access_token,$record_type,$post_fields){
        $amazon_profile_url = $this->advertising_url.'/'.$record_type.'/snapshot';
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'),$post_fields);
    }

    public function getAmazonSnapshotInformation($access_token,$report_id){
        $amazon_profile_url = $this->advertising_url.'/snapshot/'.$report_id;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'));
    }

    public function getAmazonSnapshotInformationFile($access_token,$location){
        $amazon_profile_url = $location;
    
        return $this->amazon_curl($amazon_profile_url,$access_token,Session::get('amazon_user_id'),array(),true);
    }

    public function updateAmazonCampaign($access_token,$post_fields = array()){
        $amazon_profile_url = $this->advertising_url.'/campaigns';
    
        return $this->amazon_update($amazon_profile_url,
                                  $access_token,
                                  Session::get('amazon_user_id'),
                                  $post_fields
                                  );
    }


    /*
     *  Function to update the user's Amazon account
     *  @param string $url
     *  @param string $access_token - live access token or refresh token
     *  @param string $profile_id
     *  @param array() $post_fields - array of post fields to update the user's Amazon account
     *  @param bool $file_download
     */
    private function amazon_update($url,$access_token,$profile_id = '',$post_fields = array(), $file_download = false ){
        //Start cURL object
        $curl_handle=curl_init();

        $authorization = "Authorization: bearer ".$access_token;
        
        // Check if the Amazon user's profile id is passed
        if($profile_id != ''){
            $scope = '';
            $scope = "Amazon-Advertising-API-Scope: ".$profile_id; 
            curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization, $scope ));
        } else {
            curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
        }
        

        //POST Fields
        if (count($post_fields)){
            curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($curl_handle, CURLOPT_POSTFIELDS,json_encode($post_fields));
        }
       

        //Bind URL
        curl_setopt($curl_handle, CURLOPT_URL,$url);
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 1);
        
        //Set Timeout
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT,2);
        
        //Force Return of Contents
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER,1);
               
        // Useful for snapshot downloads
        if($file_download){
            curl_setopt($curl_handle, CURLOPT_VERBOSE, 1);
            curl_setopt($curl_handle, CURLOPT_HEADER, 1);
        }

        //Store returned data
        $buffer = curl_exec($curl_handle);
        
        if($file_download){
            $header_size = curl_getinfo($curl_handle, CURLINFO_HEADER_SIZE);
            $header_arr = $this->get_headers_from_curl_response($buffer);
            return gzfile($header_arr['Location']);
        }
         //Close the cURL object, freeing up the request memory
        curl_close($curl_handle);
        
        //if the buffer is not false, then it returned something
        if ($buffer !== false) {
            //Return the buffer string
            return json_decode($buffer);
        } else {
            return false;
        }

    }

    /*
     *  Function to update the user's Amazon account
     *  @param string $url
     *  @param string $access_token - live access token or refresh token
     *  @param string $profile_id
     *  @param array() $post_fields - array of post fields to update the user's Amazon account
     *  @param bool $file_download
     */
    private function amazon_curl($url,$access_token,$profile_id = '',$post_fields = array(), $file_download = false ){

        // Test if the user has an active token
        $this->loginAPI();

        // If the Access token doesn't exist or invalid, refresh the token
        if( Session::get('access_token') == '' && Auth::user()->refresh_token != '' ){
            $this->useRefreshToken('refresh_token',Auth::user()->refresh_token);
        }

    	//Start cURL object
        $curl_handle=curl_init();

        $authorization = "Authorization: bearer ".$access_token;
        $scope = '';

        if($profile_id == ''){
            $this->loginAPI();
        }

        if($profile_id != ''){

            $scope = "Amazon-Advertising-API-Scope: ".$profile_id; 

            curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization, $scope ));
        } else {
            curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
        }
        

        //POST Fields
        if (count($post_fields)){
            curl_setopt($curl_handle, CURLOPT_POST, 1);
            curl_setopt($curl_handle, CURLOPT_POSTFIELDS,json_encode($post_fields));
        }
       

        //Bind URL
        curl_setopt($curl_handle, CURLOPT_URL,$url);
        
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 1);
        
        //Set Timeout
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT,2);
        
        //Force Return of Contents
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER,1);
               
        // Download file if necessary
        if($file_download){
            curl_setopt($curl_handle, CURLOPT_VERBOSE, 1);
            curl_setopt($curl_handle, CURLOPT_HEADER, 1);
        }

        //Store returned data
        $buffer = curl_exec($curl_handle);
        
        if($file_download){
            $header_size = curl_getinfo($curl_handle, CURLINFO_HEADER_SIZE);
            $header_arr = $this->get_headers_from_curl_response($buffer);
            return gzfile($header_arr['Location']);
        }

        //Close the cURL object, freeing up the request memory
        curl_close($curl_handle);
        
        //if the buffer is not false, then it returned something
        if ($buffer !== false) {
            //Return the buffer string
            return json_decode($buffer);
        } else {
            return false;
        }
    }



    /*
     * string curl_pull(string $url)
     *  @param string $url        - The full url of the request.
     *        Uses cURL to pull the data from the server, with the given URL.
     */
    private function curl_pull($url) {
        //Start cURL object
        $curl_handle=curl_init();
        
        //Bind URL
        curl_setopt($curl_handle, CURLOPT_URL,$url);
        
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 1);
        
        //Set Timeout
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT,2);
        
        //Force Return of Contents
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER,1);
        
        //Store returned data
        $buffer = curl_exec($curl_handle);
        
        //Close the cURL object, freeing up the request memory
        curl_close($curl_handle);
        
        //if the buffer is not false, then it returned something
        if ($buffer !== false) {
            //Return the buffer string
            return json_decode($buffer);
        } else {
            return false;
        }
    }

    /*
     *  Function to process the headers for the cURL responses
     *  @param string $response
     */
    private function get_headers_from_curl_response($response){
        $headers = array();

        $header_text = substr($response, 0, strpos($response, "\r\n\r\n"));

        foreach (explode("\r\n", $header_text) as $i => $line)
            if ($i === 0)
                $headers['http_code'] = $line;
            else
            {
                list ($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }

        return $headers;
    }
  
}

?>