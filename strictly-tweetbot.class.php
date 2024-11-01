<?php


//error_reporting(E_ERROR);
//ini_set('display_errors', 1);

if(!function_exists('is_tweet_me')){

	// turn debug on for one IP only
	function is_tweet_me(){	
		
		$ip = "";           
		if (getenv("HTTP_CLIENT_IP")){ 
			$ip = getenv("HTTP_CLIENT_IP"); 
		}elseif(getenv("HTTP_X_FORWARDED_FOR")){
			$ip = getenv("HTTP_X_FORWARDED_FOR"); 			
		}elseif(getenv("REMOTE_ADDR")){
			$ip = getenv("REMOTE_ADDR");
		}else {
			$ip = "NA";
		}
		
		// put your IP here
		if($ip == "182.34.48.25" || $ip == "2.12.4.117"){
			return true;
		}else{
			return false;
		}

	}
}

if(!function_exists('ShowTweetBotDebug')){

	// if the DEBUG constant hasn't been set then create it and turn it off
	if(!defined('TWEETBOTDEBUG')){
		if(is_tweet_me()){
			define('TWEETBOTDEBUG',false);
		}else{
			define('TWEETBOTDEBUG',false);
		}
	}

	/**
	 * function to output debug to page
	 *
	 * @param string $msg
	 */
	function ShowTweetBotDebug($msg){
		if(TWEETBOTDEBUG){
			if(!empty($msg)){
				if(is_array($msg)){
					print_r($msg);
					echo "<br />";
				}else if(is_object($msg)){
					var_dump($msg);
					echo "<br />";
				}else if(is_string($msg) || is_numeric($msg)){
					echo htmlspecialchars($msg) . "<br>";
				}				
			}
		}
	}
}


// in test mode we dont actually send the tweets
define('TESTMODE',FALSE); 
define('IGNOREAUTOTAG',FALSE); // turn on to ignore AutoTag hook ALWAYS even if Strictly AutoTags is installed
define('TWEETMAXLEN',137); // the max length of the tweet, change to ensure tweet is always under the max length of 140

// required for URL shortening
define('STRICTLY_BITLY_API_SHORTEN_URL', 'http://api.bit.ly/shorten');
define('STRICTLY_BITLY_API_SHORTEN_URL_JMP', 'http://api.j.mp/shorten');
define('STRICTLY_BITLY_API_VERSION', '2.0.1');

// required for oAuth
define('APP_CONSUMER_KEY', 'ZhQu9dymcSuK18gjru9g');
define('APP_CONSUMER_SECRET', 'P6Fd7Br6e4dHIKg3XoqRPtmzTH1oDlhDjE07Eq0VXfM');


class StrictlyTweetBot{

	protected $plugin_name	= "Strictly TweetBot";

	/**
	* The kind of version this is
	*
	* @access protected
	* @var string
	*/
	protected $version_type = "FREE";

	/**
	* current free version of plugin 
	*
	* @access protected
	* @var string
	*/
	protected $free_version = "1.1.5";

	/**
	* latest paid for version
	*
	* @access protected
	* @var string
	*/
	protected $paid_version = "1.1.6";


	protected $version		= 1;

	protected $build		= 1.5;

	protected $author		= 'Robert Reid';

	protected $company		= 'Strictly Software';
	
	protected $website		= 'http://www.strictly-software.com';

	protected $defaultuseragent = '';
	
	protected $cacheuseragent = '';

	protected $accounts;

	protected $account_names;

	protected $access_token_secrets;

	protected $access_tokens;
	
	protected $verified;

	protected $defaulttags;

	protected $tagtypes;

	protected $active;

	protected $uninstall;
	
	protected $formats;

	protected $contentanalysis;

	protected $contentanalysistype;

	protected $saved_keys;

	protected $bitlyAPIkey;

	protected $bitlyAPIusername;

	protected $bitlyAPI;

	protected $messages;

	protected $pluginurl;

	protected $clean_siteurl;

	protected $pluginpath;

	protected $rootpath;

	protected $install_date;

	protected $extra_querystring;

	protected $ignoreterms;

	protected $tweetshrink;

	protected $textshrink;

	protected $loadpagebeforetweeting; // if caching is enabled on the site then you can set this to call the page by a remote request before tweeting so it gets cached may need to turn off various options depending on caching plugin

	public function __construct(){

		ShowTweetBotDebug("IN TWEETBOT CONSTRUCT!");

		// set up useragent
		$this->defaultuseragent	= 'Mozilla/5.0 (' . $this->website . ') ' . $this->plugin_name . '/' . $this->version . '.' . $this->build;

		$this->clean_siteurl	= untrailingslashit(get_option('siteurl')) . "/";		

		$this->pluginpath		= trailingslashit(str_replace("\\","/",dirname(__FILE__))); //handle windows
		$this->rootpath			= untrailingslashit(StrictlyTweetControl::GetHomePath());	
		$this->pluginurl		= plugin_dir_url(__FILE__);

		
		// set up values for config options 
		$this->GetOptions();

		// load any language specific text
		load_textdomain('strictlytweetbot', dirname(__FILE__).'/language/'.get_locale().'.mo');

		// add options to admin menu
		add_action('admin_menu', array(&$this, 'RegisterAdminPage'));
				
		// set a function to run whenever posts are saved that will call our AutoTag function
		// so I can quickly over rule auto-tagging if it's going wrto
		if(IGNOREAUTOTAG){
			ShowTweetBotDebug("IGNORE AUTOTAG AND POST TWEETS ASAP");
			add_action( 'publish_post', array($this, 'PostTweets') , 999);
		}else{
			ShowTweetBotDebug("WE USE AUTOTAG AND POST ON TAG FINISHED");
			add_action( 'publish_post', array($this, 'CheckAndPostTweets') , 999);
		}
		
		

		ShowTweetBotDebug("TweetBot init finished");
	}
	
	/**
	 * Checks whether we hook into the AutoTag plugin 
	 *
	 */
	public function CheckAndPostTweets($post_id = 0){
		
		ShowTweetBotDebug("IN CheckAndPostTweets for post id " . $post_id);

		$name = "tagging_post_" . $post_id;

		ShowTweetBotDebug("Are we currently tagging this post $name get_option returns " . intval( get_option($name) ));

		if($this->CanWePostTweets($post_id)){

			ShowTweetBotDebug("returns true");
			
			ShowTweetBotDebug("is Strictly AutoTags installed?");

			// use the function as I've been told if the plugin is de-activated then the functions won't be accessible
			if(function_exists('ShowDebugAutoTag')){
				$strictly_auto_tags_active = true;
			}else{
				$strictly_auto_tags_active = false;
			}	
			
			ShowTweetBotDebug("is strictly autotags active = " . $strictly_auto_tags_active);

			if(	$strictly_auto_tags_active ){
				
				ShowTweetBotDebug("AutoTags is active - have we tweeted already?");

				$tweeted_already = get_post_meta($post_id, 'strictlytweetbot_posted_tweet', true);

				ShowTweetBotDebug("tweeted already = " . $tweeted_already);

				if(!$tweeted_already){
					// check whether my tag plugin is installed and active by checking the array of active plugins and we havent tweeted already
					//if (is_plugin_active('strictly-autotags/strictlyautotags.class.php') && get_post_meta($post_id, 'strictlytweetbot_posted_tweet', true) !== '1') {

					ShowTweetBotDebug("Strictly AutoTags is loaded so wait until the finished_doing_tagging event fires for $post_id");

					// hook post_tweets into our finished_doing_tagging EVENT
					add_action('finished_doing_tagging',array($this, 'PostTweets'),99,1);			
				}else{
					ShowTweetBotDebug("Strictly AutoTags is loaded but we have already tweeted! so RETURN FALSE");

					return;
				}
			}else{
				
				ShowTweetBotDebug("no Strictly AutoTags and no tags/categories so just post tweets now for $post_id");

				// just run normal tweet code
				$this->PostTweets($post_id);
			}

		}else{

			ShowTweetBotDebug("We dont tweet for this post! CanWePostTweets returns false");
		}

		ShowTweetBotDebug("RETURN from CanWePostTweets");

		return;
	}

	/**
	 * Returns the correct API URL for shortening 
	 *
	 * @param string $url
	 * @returns string
	 *
	 */
	protected function GetShortenAPIUrl($url){
		
		$apiurl = "";

		switch ($this->bitlyAPI){

			case "bit.ly":
				$apiurl	= STRICTLY_BITLY_API_SHORTEN_URL.'?version='.STRICTLY_BITLY_API_VERSION.'&longUrl='.urlencode($url);
				break;
			case "j.mp":
				$apiurl	= STRICTLY_BITLY_API_SHORTEN_URL_JMP.'?version='.STRICTLY_BITLY_API_VERSION.'&longUrl='.urlencode($url);
				break;
		}

		if (!empty($this->bitlyAPIusername) && !empty($this->bitlyAPIkey)) {
			$apiurl .= '&login='.urlencode($this->bitlyAPIusername).'&apiKey='.urlencode($this->bitlyAPIkey); //.'&history=1';
		}

		return $apiurl;	
		
	}


	/**
	 * Shortens a tweet message by using the TweetShrink API > http://tweetshrink.com/api
	 *
	 * @param string $content
	 * @returns string
	 *
	 */
	protected function TweetShrink($content) {

		ShowTweetBotDebug("IN TweetShrink $content");

		if(!empty($content)){

			$url = "http://tweetshrink.com/shrink?format=string&text=" . urlencode($content);

			ShowTweetBotDebug("shrink with API call to $url");

			$http = (array)wp_remote_get($url);
			//$http = wp_remote_get($url);
	
			$result = $http["body"];
			//$result = $http;

			unset($http);

			// if our shrunken result isn't less than original keep original
			if(!empty($result) && strlen($result) < strlen($content)){

				ShowTweetBotDebug("TweetShrink API shortened result from " . strlen($content) . " to " . strlen($result) . " so return $result");

				// shrink didnt help so return original
				return $result;
			}else{
				ShowTweetBotDebug("TweetShrink API didn't help shorten can we do something quick");

				// call QuickShrink
				$result = $this->QuickShrink($result);
				
			}
		}

		ShowTweetBotDebug("return $content");

		return $content;

	}

	/* Shortens a tweet message quickly by just removing some quick characters used by the TweetShrink API if it doesn't shorten and the Strictly Text Shrink API
	 *
	 * @param string $content
	 * @returns string
	 *
	 */
	protected function QuickShrink($content) {

		ShowTweetBotDebug("IN QuickShrink $content");

		if(!empty($content)){

			$content = preg_replace("@[-,.'\"‘’]@i","",$content);
			$content = preg_replace("@(\b)(and)(\b)@i","$1&$3",$content);
			$content = preg_replace("@(\b)(have)(\b)@i","$1hv$3",$content);
			$repval = "2";
			$repval2 = "8";
			$content = preg_replace("@(\b)(to)(\b)@ie","$1'$repval'$3",$content);
			$content = preg_replace("@(\b)(be)(\b)@ie","$1b$3",$content);
			$content = preg_replace("@(\b)(ate)(\b)@ie","$1'$repval2'$3",$content);
			$content = preg_replace("@,\s@i"," ",$content);

		}

		return $content;
	 }

	/**
	 * Shortens a tweet message by using my own method which replaces some common words with short text speak like versions
	 *
	 * @param string $content
	 * @returns string
	 *
	 */
	protected function StrictlyShrink($content){


		ShowTweetBotDebug("IN StrictlyShrink $content");

		if(!empty($content)){
			

			$rep = array( 'are'=>'R', 'become' => 'bcum','queue'=>'Q', 'mother fucking'=>'muthafuckin','mother fucker'=>'muthafucka','tea'=>'T','you\'re'=>'yr','your'=>'ur', 'card'=>'crd', 'you'=>'U', 'and'=>'&', 'greater'=>'>', 'less'=>'<', 'why'=>'Y', 'come'=>'cum', 'back'=>'bak', 'help'=>'hlp', 'what'=>'wat','please' => 'pls', 'read' => 'rd', 'be' => 'B', 'about'=>'abt', 'hours'=>'hrs','play'=>'ply', 'first'=>'1st','second'=>'2nd','third'=>'3rd','fourth'=>'4th','fifth'=>'5th','down'=>'dn', 'because'=>'coz', 'when'=>'wen','this'=>'ths','would'=>'wud','could'=>'cud','great'=>'gr8','government'=>'gov','prime minister'=>'PM','morning'=>'AM','afternoon'=>'PM','evening'=>'PM', 'like'=>'lk', 'with'=>'wiv','noone'=>'no1','no-one'=>'no1', 'dollars'=>'$', 'money'=>'$','cash'=>'$', 'pounds'=>'£', 'big bucks'=>'$$', 'lots of cash'=>'$$','video'=>'vid','videos'=>'vids','double'=>'dbl','single'=>'sngl','love'=>'luv','see'=>'c','hundreds'=>"00\'s",'thousands'=>"000\'s","don\'t know"=>'dnt no','listen'=>'lstn','Triple X'=>'XXX', 'Double Penetration'=>'DP', 'don\'t'=>'dont', 'cannot'=>'cant', 'can\'t'=>'cant', 'won\t'=>'wont', 'will not'=>'wont', 'President of the United States of America'=>'POTUS', 'President of America'=>'POTUS', 'US President'=>'US Pres', 'Tomorrow'=>'2mrw', 'tonight'=>'2nite', 'night'=>'nite','television'=>'TV','message'=>'msg','thanks'=>'thnx','hello'=>'hi','goodbye'=>'bye','goodnight'=>'gdnite','mother'=>'mum','father'=>'dad','grandmother'=>'gran','grandfather'=>'grampa','granny'=>'gran','granddad'=>'grampa','ever'=>'evr','over'=>'ovr','how'=>'hw','in my opinion'=>'IMO','laugh out loud'=>'LOL','peer 2 peer'=>'P2P','send'=>'snd','in my honest opinion'=>'IMHO', 'plus'=>'+', 'United Kingdom' => 'UK', 'United States' => 'USA', 'United States of America'=>'USA','Great Britain'=>'GBR', 'Ireland'=>'IRE', 'England'=>'ENG', 'to'=>'2','for'=>'4', 'before'=>'b4','free'=>'3', 'police'=>'cops', 'street'=>'st', 'road'=>'rd', 'zero'=>'0', 'none'=>'0', 'Saint'=>'St', 'Sir'=>'Sr', 'King'=>'Kng', 'Lord'=>'Lrd', 'Major'=>'Mjr', 'prison'=>'jail', 'debate'=>'db8');


			//ShowTweetBotDebug($rep);

			/*	loop through each pair of replacements and do 3 replaces
				1. Do a replacement of any upper case words that match
				2. Do a replacement of any lower case words that match
				3. Do a replacement of any mixed case words that match	*/
			foreach ($rep as $key=>$value){

				//ShowTweetBotDebug("in loop 1 doing $key => $value");

				$repval = $value;
				$regex = "@(\b)(" .  preg_quote( $key ,"@") . ")(\b)@";
				$repval =  preg_quote($repval,"@");

				//ShowTweetBotDebug("replace $regex with lower case group 1 + " . preg_quote($repval,"@") . " group 2");

				$content = preg_replace("@(\b)(" .  preg_quote( $key ,"@") . ")(\b)@e","$1'$repval'$3",$content);
				
				if(!is_numeric($value)){

					$repval2 = strtoupper($value);
					$regex2 = "@(\b)(" .  preg_quote( strtoupper($key) ,"@") . ")(\b)@";
					$repval2 =  preg_quote($repval2,"@");

					//ShowTweetBotDebug("replace $regex2 with upper case group 1 + " . preg_quote($repval2,"@") . " group 2");

					$content = preg_replace("@(\b)(" .  preg_quote( strtoupper($key) ,"@") . ")(\b)@e","$1'$repval2'$3",$content);

					// now just do an ignore case as we might have mixed case words that didnt match
					$repval = $value;
					$regex = "@(\b)(" .  preg_quote( $key ,"@") . ")(\b)@i";
					$repval =  preg_quote($repval,"@");

					//ShowTweetBotDebug("replace $regex with group 1 + " . preg_quote($repval,"@") . " group 2");

					$content = preg_replace("@(\b)(" .  preg_quote( $key ,"@") . ")(\b)@ie","$1'$repval'$3",$content);
					
				}else{
					//ShowTweetBotDebug($value . " is NUMERIC no need for upper case");
				}
				

				//ShowTweetBotDebug("content is now == '$content'");

			}


			//ShowTweetBotDebug("now do the ones without boundaries");

			// lower case endings of words

			$rep = array( "ing"=>"in", "uck"=>"uk", "ate"=>"8", "fore"=>"4");

			ShowTweetBotDebug($rep);

			foreach ($rep as $key=>$value){

				//ShowTweetBotDebug("in loop 2 doing $key => $value");

				$repval = $value ."$2";
				$regex = "@(" . $key . ")(\b)@";
				//$repval =  preg_quote($repval,"@");

				//ShowTweetBotDebug("repval == $repval");

				$content = preg_replace($regex, $repval,$content);

				//ShowTweetBotDebug("using $regex and $repval content is now == '$content'");

				if(!is_numeric($value)){

					$repval2 = strtoupper($value)."$2";
					//$repval2 =  preg_quote($repval2,"@");

					
					//ShowTweetBotDebug("repval == $repval2");

					
					$regex2 = "@(" . strtoupper($key) . ")(\b)@";

					
					$content = preg_replace($regex2, $repval2,$content);

					//ShowTweetBotDebug("using $regex2 and $repval2 content is now == '$content'");				
				}else{
					//ShowTweetBotDebug($value . " was NUMERIC so no need for capitalised version");
				}

			}

			// special endings
			$rep = array( "er"=>"r", "ers"=>"rs", "ed"=>"d");

			//ShowTweetBotDebug($rep);

			foreach ($rep as $key=>$value){

				ShowTweetBotDebug("in loop 3 doing $key => $value");

				$repval = "$1" . $value ."$2";
				//$repval =  preg_quote($repval,"@");
				
				// make sure the preceeding letter is not a vowel
				$regex = "@([^aeuio]+?)" . preg_quote($key) . "(\b)@";

				//ShowTweetBotDebug("replace $repval using $regex");

				$content = preg_replace($regex, $repval,$content);

				//ShowTweetBotDebug("using content is now == '$content'");

				if(!is_numeric($value)){

					$repval2 = strtoupper($value)."$2";

					
					//ShowTweetBotDebug("repval == $repval2");

					
					$regex2 = "@(" . strtoupper($key) . ")(\b)@";

					
					$content = preg_replace($regex2, $repval2,$content);

					//ShowTweetBotDebug("using $regex2 and $repval2 content is now == '$content'");				
				}else{
					//ShowTweetBotDebug($value . " was NUMERIC so no need for capitalised version");
				}

			}






			// case irelevant case


			$rep = array(   'one' => '1', 'ten'=>'10', 'two'=>'2', 'three'=>'3','five'=>'5','six'=>'6','seven'=>'7','eight'=>'8','nine'=>'9','eleven'=>'11','twelve'=>'12' ,'thirteen'=>'13','fourteen'=>'14','fifteen'=>'15','sixteen'=>'16','seventeen'=>'17','eighteen'=>'18' ,'nineteen'=>'19','twenty'=>'20' ,'thirty'=>'30','fourty'=>'40' ,'fifty'=>'50' ,'sixty'=>'60' ,'seventy'=>'70' ,'eighty'=>'80' ,'ninety'=>'90','hundred'=>'100' ,'thousand'=>'1000', 'million'=>'mn', 'billion'=>'bn');

			//ShowTweetBotDebug($rep);

			foreach ($rep as $key=>$value){

				//ShowTweetBotDebug("doing $key => $value");

				$repval = $value;
				$repval =  preg_quote($repval,"@");

				//ShowTweetBotDebug("repval == $repval");

				$regex = "@(\b)" . $key . "(\b)@i";

				$content = preg_replace($regex, $repval,$content);

				
				//ShowTweetBotDebug("using $regex and $repval content is now == '$content'");

			}

			
			//ShowTweetBotDebug("now remove symbols we can do without e.g hyphens or apostrophes");

			$content = preg_replace("@['”‘’\",.:;]@", "",$content);

			//ShowTweetBotDebug("now remove excess space");

			$content = preg_replace("@\s+@", " ",$content);

		}

		//ShowTweetBotDebug("return == '$content'");


		return $content;
	}


	
	/**
	 * Shotens a tweet by using the specified URL shortener
	 *
	 * @param string $url
	 * @returns string
	 *
	 */
	protected function BitlyShortenUrl($url) {

		$parts = parse_url($url);
		
		// skip links already shortened
		if(!preg_match("@(bit\.ly|tinyurl\.com|strurl\.com|t\.co)@i",$parts['host'])){
			
			$api	= $this->GetShortenAPIUrl($url);					
					
			$http = (array)wp_remote_get($api);
			
			$result = json_decode($http["body"]);
			if (!empty($result->results->{$url}->shortUrl)) {
				$url = $result->results->{$url}->shortUrl;
			}
		}

		return $url;
	}
	
	/**
	 * Looks for urls within a message and then replaces them with a short version
	 *
	 * @returns string
	 *
	 */
	protected function BitlyShortenTweet($tweet) {
	
		
		preg_match_all('$\b(https?|ftp|file)://[-A-Z0-9+&@#/%?=~_|!:,.;]*[-A-Z0-9+&@#/%=~_|]$i', $tweet, $urls);
		if (isset($urls[0]) && count($urls[0])) {
			foreach ($urls[0] as $url) {
				// borrowed from WordPress's make_clickable code
				if ( in_array(substr($url, -1), array('.', ',', ';', ':', ')')) === true ) {
					$url = substr($url, 0, strlen($url)-1);
				}
				$tweet = str_replace($url, $this->BitlyShortenUrl($url), $tweet);
			}
		}
		
		return $tweet;
	}
	

	/**
	 * Register AdminOptions with Wordpress
	 *
	 */
	public function RegisterAdminPage() {
		add_options_page('Strictly Tweet Bot', 'Strictly Tweet Bot', 'manage_options', basename(__FILE__), array(&$this,'AdminOptions'));	
	}

	
	/**
	 * Test the plugin configuration 
	 *
	 * @returns string
	 *
	 */
	protected function TestConfig(){

		ShowTweetBotDebug("IN TestConfig");

		global $wpdb;


		$msg[] = __("Starting Strictly Tweetbot Configuration Check...","strictlytweetbot");

		// loop through all active accounts
		if(!is_array($this->accounts)){
			
			$msg[] = __("There are no Tweetbot Accounts to check!","strictlytweetbot");
			return;
		}

		// check for URL shortening
		if(empty($this->bitlyAPIusername) || empty($this->bitlyAPIkey)){
			$msg[] = __("Bit.ly URL shortening needs configuring.","strictlytweetbot");
		}else{
			
			$url = get_option('siteurl');

			$msg[] = sprintf(__("Testing Bit.ly configuration with URL: %s.","strictlytweetbot"),$url);

			// get API details
			$api = $this->GetShortenAPIUrl($url);

			$msg[] = sprintf(__("URL shortening API is set to: %s.","strictlytweetbot"),$api);


			//$api	= STRICTLY_BITLY_API_SHORTEN_URL.'?version='.STRICTLY_BITLY_API_VERSION.'&longUrl='.urlencode($url) . '&login='.urlencode($this->bitlyAPIusername).'&apiKey='.urlencode($this->bitlyAPIkey).'&history=1';

			$http = (array)wp_remote_get($api);

			/*
			{"errorCode": 203, "errorMessage": "You must be authenticated to access shorten", "statusCode": "ERROR"}
			*/

			// default to full URL in case shorterner doesn't work
			$short_url = $url;

			if($http["response"]["code"] == "200"){
				
				ShowTweetBotDebug("full body response = " . $http["body"]);

				$result = json_decode($http["body"]);

				// might get a 200 code but a bit.ly error
				if(isset($result->errorCode) && $result->errorCode == 0){
					
					// set short url
					$short_url = $result->results->{$url}->shortUrl;

					$msg[] = sprintf(__("Bit.ly returned a short URL of %s and is configured correctly.","strictlytweetbot"), $short_url);

				}elseif( isset($result->errorCode) && $result->errorCode > 0 ){
					
					// a known error
					$msg[] = sprintf(__("Bit.ly returned an %s status with an error code: %d. %s.","strictlytweetbot"), $result->statusCode,$result->errorCode,$result->errorMessage);

				}else{
					// an unknown error
					$msg[] = sprintf(__("Bit.ly returned an unrecognised response: ","strictlytweetbot"), htmlspecialchars($http["body"]));
				}

			}else{
				$msg[] = sprintf(__("Request to Bit.ly API returned an HTTP status code of %d.","strictlytweetbot"),$http["response"]["code"]);
			}


		}


		// test shrinking options


		// in future rewrite this with a branch so that if we are looking at posts with no tags then
		// we only return from the DB those posts that have no tags

		// fix for WP now checking for % inside the string as its not needed - but causes lots of pain to me!
		$sql =  "SELECT	id, post_title
				FROM	{$wpdb->posts} wp								
				WHERE	post_password='' AND post_status='publish' AND post_type='post' 
				ORDER BY post_modified_gmt DESC
				LIMIT 0,1;";
		

		ShowTweetBotDebug($sql);

		$posts = $wpdb->get_results($sql);
		
		foreach($posts as $post){
			
			$post_title = $post->post_title;		
			$post_id	= $post->id;		
			$permalink	= get_permalink($post_id);

			ShowTweetBotDebug("do test with post id: " . $post_id . " and title: " . $post_title . " and " . $permalink);

		}

		// clean up
		unset($posts,$post,$sql);
		
		ShowTweetBotDebug("last post title was $post_title ");


		// if HTTP request is on
		if($this->loadpagebeforetweeting)
		{

			$msg[] = sprintf(__("Testing HTTP Cache Request by making a call to %s with user-agent: %s","strictlytweetbot"),$permalink,$this->cacheuseragent);

			// get status code back;
			$http = (array)wp_remote_get($permalink,array('user-agent'=>$this->cacheuseragent,'timeout'=>30,'blocking'=>true));

			if(isset($http["response"])){

				$status = $http["response"]["code"];
				$message= $http["response"]["message"];
				
				if($status == "200"){
					$msg[] = __("HTTP Request returned a successful status code of 200","strictlytweetbot");
				}else{
					if(!empty($message)){
						$msg[] = sprintf(__("HTTP Request returned an unsuccessful status code of %s and a message of %s","strictlytweetbot"),$status,$message);
					}else{					
						$msg[] = sprintf(__("HTTP Request returned an unsuccessful status code of %s","strictlytweetbot"),$status);
					}
				}
				
			}elseif(isset($http["errors"])){
				
				$message= implode($http["errors"]["http_request_failed"],"");
				$msg[] = sprintf(__("HTTP Request to %s failed with a message of: %s","strictlytweetbot"),$permalink,$status);

			}else{
				
				$message= "An unknown error occurred making an HTTP request to: $permalink";
				$msg[] = sprintf(__("HTTP Request failed with a message of: %s","strictlytweetbot"),$message);
			}
		}


		$msg[] = sprintf(__("Testing shrinking options including the Tweet Shrink API and Strictly Text Shrink with the latest published article title: %s","strictlytweetbot"),$post_title);
	
		// code to block non payers!

		$tweetshrink_title = $this->TweetShrink($post_title);
		$msg[] = sprintf(__("Tweet Shrink API returns: '%s' which is %s (chars long)","strictlytweetbot"),$tweetshrink_title, strlen($tweetshrink_title));
	

		$textshrink_title = $this->StrictlyShrink($post_title);
		$msg[] = sprintf(__("Strictly Text Shrink returns: '%s' which %s (chars long)","strictlytweetbot"),$textshrink_title, strlen($textshrink_title));
	
		// now do both
		$veryshrunken_title = $this->StrictlyShrink($tweetshrink_title);
		$msg[] = sprintf(__("Tweet Shrink API and Strictly Text Shrink returns: '%s' which %s (chars long)","strictlytweetbot"),$veryshrunken_title, strlen($veryshrunken_title));
	

		$test_tweet_format = "please read this article > %title% %url% %hashtags%";

		$msg[] = sprintf(__("Testing shrinking options for the whole tweet using the Tweet format of: '%s'","strictlytweetbot"),$test_tweet_format);

		// try and use hash tags for the post, otherwise categories otherwise make some default hash tags up
		$unformatted_hash_tags = "";
		$test_tags = $hash_tags = "";
		$postterms = get_the_tags($post_id);

		// for testing with default hardcoded tags
		/*
		if(1==1){

			$hash_tag_test_type = "default hashtags";
			$test_tags			= "test tag one, test_tag_two, John Smith, Dave's Garage, 911, 360 Degrees";

			// create array
			$postterms			= explode(",",$test_tags);

		}else{
		(*/
			// if no tags use categories
			if (!is_array($postterms)){
		
				$hash_tag_test_type = "no post terms try for categories";

				$postterms = get_the_category($post_id);

				// if no categories use defaults
				if (!is_array($postterms)){
				
					ShowTweetBotDebug("no categories use default hash tags");

					$hash_tag_test_type = "default hashtags";
					$test_tags			= "test tag one, test_tag_two, John Smith, Dave's Garage, 911";

					// create array
					$postterms			= explode(" ",$test_tags);
				}else{
					
					$hash_tag_test_type = "categories";
				}
			}else{
				
				$hash_tag_test_type = "post tags";
			}
		//}
		
		ShowTweetBotDebug("hash tag type is " . $hash_tag_test_type);

		foreach($postterms as $term) {
							
			if($term->name){
				$term_item = $term->name;
			// handle defaults
			}else{
				$term_item = $term;
			}

			ShowTweetBotDebug("in postterm loop term_item is $term_item");

			// format the hash tag by trimming, removing non alpha numeric characters and ensuring the final size is 15 chars or less
			$unformatted_hash_tags	= $unformatted_hash_tags . htmlspecialchars(trim($term_item)) . ", ";
			$this_hash_tag			= $this->FormatHashTag($term_item); // will retun an empty string if its too long

			ShowTweetBotDebug("formatted hash tag is " . $this_hash_tag	);

			if(!empty($this_hash_tag)){

				ShowTweetBotDebug("add to existing list of " . $hash_tags);

				$hash_tags = $hash_tags . $this_hash_tag . " ";
			}
		}

		ShowTweetBotDebug("final list of hash tags is " . $hash_tags);

		// remove trailing spaces
		$hash_tags				= trim($hash_tags);
		$unformatted_hash_tags	= preg_replace("@,$@","",trim($unformatted_hash_tags));

		ShowTweetBotDebug("Final list of hash tags to import into Tweet is '$hash_tags'");

		$msg[] = sprintf(__("The category type to replace the HashTags paramter with is: %s","strictlytweetbot"),$hash_tag_test_type);
		
		ShowTweetBotDebug("list of unformmated hash tags is " . $unformatted_hash_tags);

		$msg[] = sprintf(__("Original unformatted HashTag list was: %s"),$unformatted_hash_tags);

		ShowTweetBotDebug("final list of hash tags is " . $hash_tags);

		$msg[] = sprintf(__("Reformatted HashTag list shrunken to fit within the tweet is now: %s"),$hash_tags);
		
		$msg[] = sprintf(__("Format the same Tweet with each TweetShrink title method","strictlytweetbot"));


		ShowTweetBotDebug("format tweets with hashtags = $hash_tags");

		$test_tweet_1 = $this->FormatTweet($test_tweet_format,$short_url,$tweetshrink_title,$hash_tags);
		$test_tweet_2 = $this->FormatTweet($test_tweet_format,$short_url,$textshrink_title,$hash_tags);
		$test_tweet_3 = $this->FormatTweet($test_tweet_format,$short_url,$veryshrunken_title,$hash_tags);
		

		$msg[] = sprintf(__("Test 1 returns '%s' which is %d (chars long)","strictlytweetbot"),$test_tweet_1,strlen($test_tweet_1) );
		$msg[] = sprintf(__("Test 2 returns '%s' which is %d (chars long)","strictlytweetbot"),$test_tweet_2,strlen($test_tweet_2) );
		$msg[] = sprintf(__("Test 3 returns '%s' which is %d (chars long)","strictlytweetbot"),$test_tweet_3,strlen($test_tweet_3) );

		$msg[] = sprintf(__("Testing %d Strictly TweetBot Accounts for Twitter connectivity","strictlytweetbot"),count($this->accounts) );
		
		foreach($this->accounts as $account){
			
			if($this->active[$account]){
				$msg[] = sprintf(__("Checking Active Account: %s.","strictlytweetbot"),$account);
			}else{
				$msg[] = sprintf(__("Checking Non Active Account: %s." ,"strictlytweetbot"),$account);
			}
			

			// Connect to Twitter
			$oauth = $this->Connect($account);
			
			if($oauth===false){
				$msg[] = __("Connection to Twitter Failed.","strictlytweetbot");

				if(!$this->verified[$account]){					
					$msg[] = __("This Account needs to be verified with OAuth.","strictlytweetbot");
				}
			}else{

				// get the twitter account username
				$account_name = $this->GetAccountName($account);

				//ShowTweetBotDebug("the account name for $account is $account_name");
				
				// verfiy account details
				if($this->TestConnection($oauth,$account_name)){

					$msg[] = __("This Twitter Account is setup correctly.","strictlytweetbot");
				}else{

					//ShowTweetBotDebug("the username $account_name did not match");

					$msg[] = sprintf(__("Please re-configure this account. Ensure the Tweetbot Username matches the Twitter Username of %s","strictlytweetbot"),$account_name);
				}
			}

			unset($oauth,$account_name);
		}

		$msg[] = __("Strictly Tweetbot Configuration Check Completed.","strictlytweetbot");

		// join the array of messages together and return
		return "<p>" . implode("</p><p>",$msg) . "</p>";
	}

	/**
	 * Used by CheckAndPostTweets to decide whether a tweet is needed 
	 *
	 * @param integer $post_id
	 * @returns boolean
	 *
	 */
	public function CanWePostTweets($post_id = 0) {

		ShowTweetBotDebug("IN CanWePostTweets $post_id");

		$postedtweetalready = get_post_meta($post_id, 'strictlytweetbot_posted_tweet', true);

		ShowTweetBotDebug("Have we posted tweet already check from get_post_meta returned " . $postedtweetalready);

		// no post ID? no tweet
		if($post_id == 0){
			ShowTweetBotDebug("No post ID!");
			return false;
		// already tweeted?
		}else if($postedtweetalready == '1'){
			ShowTweetBotDebug("already tweeted this post");
			return false;
		}

		ShowTweetBotDebug("get post");

		// get post
		$post = get_post($post_id);

		// no post object so go
		if ( $post == null || $post == false ) {
			ShowTweetBotDebug("no post object");
			return false;
		}
		
		// check for private posts OR posts added before the plugin was installed
		if ($post->post_status == 'private'){
			ShowTweetBotDebug("post is private");
			return false;
		}

		// post was before install date - to post update the post date
		if(!empty($this->install_date) && $post->post_date <= $this->install_date) {
			ShowTweetBotDebug("before install date");
			return false;
		}
		
		ShowTweetBotDebug("RETURN TRUE");

		return true;
	}

	/**
	 * Post messages to twitter to all accounts set up correctly that meet the criteria
	 *
	 * @param integer $post_id
	 * @returns boolean
	 *
	 */
	public function PostTweets($post_id = 0) {

		ShowTweetBotDebug("IN PostTweets $post_id");

		// check for valid posts already been done

		//ShowTweetBotDebug("Post is okay lets check accounts");
		$post = get_post($post_id);

		if ( $post == null || $post == false) {
			return false;
		}

		ShowTweetBotDebug("got post title is " . $post->post_title);

		// loop through all active accounts
		if(!is_array($this->accounts)){
			return;
		}

		if(IGNOREAUTOTAG){
			ShowTweetBotDebug("IGNORE AUTOTAG - CAN WE POST?");

			if(!$this->CanWePostTweets($post_id)){
				ShowTweetBotDebug("not allowed to post tweets - RETURN FALSE");
				return false;
			}
		}

		ShowTweetBotDebug("DO TWEETING");
		
		// only need to get the link once not on each account iteration plus we need it clean in case of caching
		$permalink  = get_permalink($post_id);		


		ShowTweetBotDebug("posting tweet for post: " . $permalink);

		if($this->loadpagebeforetweeting)
		{
			ShowTweetBotDebug("Fire off request to $permalink with useragent: $cacheuseragent to try and cache page before Twitter Rush");

			// do we fire off a request to the page to help it get cached to prevent twitter rushes causing havoc non blocking so it shouldnt stop the flow of the code
			wp_remote_post($permalink, array('user-agent' => $this->cacheuseragent, 'timeout' => 0.01, 'blocking' => false));
		}

		foreach($this->accounts as $account){

			if($this->active[$account]){
			
				// only attempt posts for accounts that have been verified with OAuth

				if($this->IsVerified($account)){

					// do we need to do some content analysis?
					if(!empty($this->contentanalysis[$account])){

						ShowTweetBotDebug("Analyse content - type is " . $this->contentanalysistype[$account]);

						// if set to always then always post ignoring whats in the inputs
						if($this->contentanalysistype[$account] == "ALWAYS"){
							ShowTweetBotDebug("always allow this post no matter what");

							$allow_post = true;
						}else{

							ShowTweetBotDebug("we need to check the content");

							$allow_post = false;
							$word_count = $match = 0;

							// join the title and article together as we search both parts. Ignoring the excerpt as I assume that this would just be
							// a shorter version of the article.

							$content = $post->post_title . " " . $post->post_content;
							
							ShowTweetBotDebug("check for these words == " . $this->contentanalysis[$account]);

							// split our word list up
							$words = explode(",",$this->contentanalysis[$account]);
						
							foreach($words as $word){

								// safety check - insanity to search for one letter words
								if(strlen($word) > 1){

									ShowTweetBotDebug("check for term $word in content");

									$word_count++;

									// for an accurate search use preg_match_all with word boundaries
									// as substr_count doesn't always return the correct number from tests I did
									
									$regex = "@\b" . preg_quote( trim($word) , "/") . "\b@i";
							
									$i = preg_match_all($regex,$content,$matches);
					

									// if found then store it with the no of occurances
									if($i > 0){

										ShowTweetBotDebug("there are $i matches of $word in the content");

										// if we dont post if any words are in the article then we skip it
										if($this->contentanalysistype[$account] == "NONE"){	

											ShowTweetBotDebug("NONE so don't allow this post");

											$allow_post = false;
											break;

										// if we are doing an ANY search then one match is all we need to confirm a tweet post
										}else if($this->contentanalysistype[$account] == "ANY"){																

											ShowTweetBotDebug("ANY so allow this post");

											$allow_post = true;
											break;

										}elseif($this->contentanalysistype[$account] == "ALL"){

											ShowTweetBotDebug("ALL so wait til end");

											$match++;

										}
									}
								}							
							}
						
							
							// for AND searches all words have to match
							if($this->contentanalysistype[$account] == "ALL"){
								
								ShowTweetBotDebug("ALL words must match does $word_count = $match");

								if($word_count == $match){
									$allow_post = true;
								}
							}else if($this->contentanalysistype[$account] == "NONE"){	

								ShowTweetBotDebug("NO words must be in the content does $match = 0");

								if($match == 0){
									$allow_post = true;
								}
							}
						}
					}else{						
						$allow_post = true;	
					}

					ShowTweetBotDebug("do we allow the post = " . intval($allow_post));

					if($allow_post){

						// do we use categories or tags
						if($this->tagtypes[$account]=="tag"){
							
							ShowTweetBotDebug("use post tags");

							// get tags for this post
							$postterms = get_the_tags($post_id);

						}else if($this->tagtypes[$account]=="category"){
							
							ShowTweetBotDebug("use categories");

							// get categories for this post
							$postterms = get_the_category($post_id);

						}else{
							
							ShowTweetBotDebug("use default hash tags");

							// use default tags
							if(!empty($this->defaulttags[$account])){
								$postterms = explode(" ",$this->defaulttags[$account]);
							}else{
								$postterms = array();
							}
						}
						

						$hash_tags = "";
						
						if (is_array($postterms)) {

							ShowTweetBotDebug("check " . count($postterms) . " terms which we have");

							foreach($postterms as $term) {
								
								if($term->name){
									$term_item = $term->name;
								}else{
									$term_item = $term;
								}

								$term_item	= trim($term_item);
								$addterm	= true;

								//ShowTweetBotDebug("do we have ignore terms to check = " . $this->ignoreterms[$account]);

								// do we have a list of terms to ignore
								if(!empty($this->ignoreterms[$account])){

									//ShowTweetBotDebug("yes so check each hash tag against our list");

									$ignoreterms = explode(",",strtolower($this->ignoreterms[$account]));

									
									if (is_array($ignoreterms)) {

										//ShowTweetBotDebug("we have " . count($ignoreterms) . " ignore terms to check");

										foreach($ignoreterms as $ignore){

											//ShowTweetBotDebug("check whether " . strtolower($term_item) . " == $ignore");

											// case insensitive
											if(strtolower($term_item) == trim($ignore)){

												//ShowTweetBotDebug("IGNORE THIS TERM!!");

												// found term to ignore
												$addterm = false;
												break;
											}
										}
									}
								}
								
								//ShowTweetBotDebug("do we add this term = " . $term_item . " addterm = " . $addterm);

								if($addterm){

									//ShowTweetBotDebug("yes add the term");
									
									$this_hash_tag = $this->FormatHashTag($term_item); // defaults to a max length of 15 characters after reformatting

									if(!empty($this_hash_tag)){
										$hash_tags = $hash_tags . $this_hash_tag . " ";
									}

								}
							}
						}

						
						// if empty use the defaults
						if(empty($hash_tags)){

							ShowTweetBotDebug("hash tags are empty probably due to no categories or hash tags exist yet so we use defaults");

							$hash_tags = $this->defaulttags[$account];
						}
	

						// do we need to add something to the end of the link before shortening it e.g a google tracker code
						if(!empty($this->extra_querystring[$account])){
							
							$newurl		= "";

							// should we just append it or try and resolve issues with existing querystring and rewritten urls?

							
							// if there is no querystring already in the link so just add one
							if(strpos($permalink , "?") === false){
								if(substr(	$this->extra_querystring[$account],0, 1 ) == "?" ){
									$newurl = $permalink . $this->extra_querystring[$account];
								}elseif(substr(	$this->extra_querystring[$account],0, 1 ) == "&" ){
									// take off the leading & and replace it with a ?
									$newurl = $permalink . "?" . substr( $this->extra_querystring[$account] , 1);
								}else{
									// just add a ? and then whatever they asked for
									$newurl = $permalink . "?" . $this->extra_querystring[$account];
								}									
							}else{
								// add as a new parameter
								if(substr(	$this->extra_querystring[$account],0, 1 ) == "&" ){
									$newurl = $permalink . $this->extra_querystring[$account];
								}elseif(substr(	$this->extra_querystring[$account],0, 1 ) == "?" ){
									// just add it
									$newurl = $permalink . "&" . substr( $this->extra_querystring[$account] , 1);
								}else{
									// just add a & and then whatever they asked for
									$newurl = $permalink . "&" . $this->extra_querystring[$account];
								}		
							}

							$url = $this->BitlyShortenUrl($newurl);
						}else{
							$url = $this->BitlyShortenUrl($permalink);
						}
						
						$post_title = $post->post_title;

						ShowTweetBotDebug("current post title = " . $post_title );

						// do we shorten the title first?
						if($this->tweetshrink[$account]){

							ShowTweetBotDebug("Tweet Shrink the title for this tweet");

							$post_title = $this->TweetShrink($post_title);

						}

						if($this->textshrink[$account]){

							ShowTweetBotDebug("Strictly Shrink the title for this tweet using text speak");

							$post_title = $this->StrictlyShrink($post_title);

						}

						ShowTweetBotDebug("current post title = " . $post_title );

						$tweet = $this->FormatTweet($this->formats[$account],$url,$post_title,$hash_tags);

						ShowTweetBotDebug("send this tweet == $tweet to $account");

						// send tweet
						$res = $this->SendTweet($account,$tweet);
					

					}else{

						$this->AddMessage(sprintf(__("The Article [%s] did not meet the content analysis criteria for Account %s. Status will not be updated.","strictlytweetbot"),$post->post_title,$account));
					}
				}else{
					
					$this->AddMessage(sprintf(__("Account: %s has not been verified. Status will not be updated.","strictlytweetbot"),$account));

				}
			}else{
				
			}				

		}

		ShowTweetBotDebug("Save messages");
		ShowTweetBotDebug($this->messages);

		// save any messages to the DB so we can report on them next time admin come to the management page
		$this->SaveMessages();

		ShowTweetBotDebug("set a posted tweet custom field with the value 1 for strictlytweetbot_posted_tweet");

		// update meta so we know that we have tweeted this post
		add_post_meta($post_id, 'strictlytweetbot_posted_tweet', '1', true);

	

		return true;

	}

	/**
	 * formats a word to be used as a hash tag by replacing non alpha numeric characters
	 * set the $maxlen paramter to the maximum length of the final formatted hash tag to allow
	 *	
	 * @param string $hashtag	
	 * @param int $maxlen
	 * @return string
	 */
	protected function FormatHashTag($hashtag, $maxlen=20){
		

		//ShowTweetBotDebug("IN FormatHashTag $hashtag $maxlen");

		// set up a max size to ignore immediatley by multipling them max final size by 3
		$maxlen2	= $maxlen * 3;
		$hashtag	= trim($hashtag);

		$hashtag = preg_replace("@ &amp; @", "", $hashtag);

		//ShowTweetBotDebug("is len of $hashtag " . strlen($hashtag) . " < $maxlen2 " . $maxlen2);

		// no point even looking at anything that is over $maxlen*3 characters long as we would have to remove 35 characters if the $maxlen was the default of 15
		if(strlen($hashtag) < $maxlen2){
		
			//ShowTweetBotDebug("yes so turn it into a hash tag");

			// might already have # in front of them - depending on who entered what - just replace all non alpha numeric characters
			$hashtag = "#" . preg_replace("@[^a-z1-9]+@i","",$hashtag);

			//ShowTweetBotDebug("hash tag is now $hashtag but is the len of " . strlen($hashtag) . " < $maxlen");

			// if length is still over our $maxlen ignore it
			if(strlen($hashtag) > $maxlen){
				return "";
			}else{
				
				//ShowTweetBotDebug("RETURN formatted hash tag = $hashtag");
				return $hashtag;
			}
		}else{
			$hashtag = "";
		}

		return $hashtag;
	}	

	/**
	 * sends a tweet to a twitter account
	 *	
	 * @param string $account
	 * @param string $twitterpost	
	 * @return boolean
	 */
	protected function SendTweet($account,$twitterpost){

		ShowTweetBotDebug("IN SendTweet to $account post this tweet message $twitterpost");

		$res = false;

		// Connect to Twitter
		$oauth = $this->Connect($account);

		
		if($oauth){


			// get the twitter account username
			$account_name = $this->GetAccountName($account);

			

			// verfiy account details
			$status = $this->TestConnection($oauth,$account_name);

			ShowTweetBotDebug("status of connection with $account_name is " . intval($status));

			if($status){

				// if we are in test mode we dont actually send the tweet
				if(!TESTMODE){

					ShowTweetBotDebug("POST TWEET");

					// ensure any %placeholders% cut in half are removed
					$twitterpost = preg_replace("@%[a-z]+%?@","",$twitterpost);

					ShowTweetBotDebug("post this exact tweet = $twitterpost");

					// Post our tweet
					$res = $oauth->post(
						'statuses/update', 
						array(
							'status' => $twitterpost,
							'source' => 'Strictly Tweetbot'
						)
					);
				}else{
					
					ShowTweetBotDebug("IN TEST MODE SO NO TWEET SENT");

					$res = json_decode("{}");
				}


				ShowTweetBotDebug("response from Twitter is below");
				ShowTweetBotDebug(json_encode($res));

				// parse error messages
				if($res){
					if($res->errors){
						ShowTweetBotDebug("errors");

						if(is_array($res->errors)){

							ShowTweetBotDebug("we have an array of errors to parse");

							foreach($res->errors as $err){					
								
								ShowTweetBotDebug("Error = " . $err->message);								

								$this->AddMessage(sprintf(__("An Error occurred posting the tweet [%s] to %s. %s","strictlytweetbot"), $twitterpost, $account_name, $err->message));

								
							}

						}
					}else{
						ShowTweetBotDebug("tweet sent ok"); 

						$this->AddMessage(sprintf(__("Tweet > [%s] was posted successfully to %s.","strictlytweetbot"),$twitterpost,$account_name));
					
						$result = true;
					}
				}else{
					ShowTweetBotDebug("Could not obtain a response from Twitter");

					$this->AddMessage(sprintf(__("An Error occurred posting the tweet [%s] to %s. %s","strictlytweetbot"),$twitterpost,$account_name,"No valid response from Twitter!"));
				}

			
				
			// verfiy failed so update account to ensure it gets revalidated
			}else{

				// verfiy failed
				$this->verified[$account] = false;	
				
				ShowTweetBotDebug("verification error sending tweet");

			}	
	
		
			unset($oauth);
		}

		// update success/failure details
		if($result){
			$this->last_tweet[$account] = false;
		}else{
			$this->last_tweet[$account] = true;
		}

		ShowTweetBotDebug("SendTweet RETURNS " . intval($result));

		return $result;

	}

	
	/**
	 * Get the twitter account name (screen name/username) for the relevant account
	 *
	 * @param string $account
	 * @return string
	 *
	 */
	protected function GetAccountName($account=""){
		
		if(!empty($account)){
			
			return $this->account_names[$account];

		}

		return "";
	}


	/**
	 * Get the access token for the relevant account
	 *
	 * @param string $account
	 * @return string
	 *
	 */
	protected function GetAccessToken($account=""){
		
		if(!empty($account)){
			
			return $this->access_tokens[$account];

		}

		return "";
	}

	/**
	 * Get the access token secret for the relevant account
	 *
	 * @param string $account
	 * @return string
	 *
	 */
	protected function GetAccessTokenSecret($account=""){
		
		if(!empty($account)){
			
			return $this->access_token_secrets[$account];

		}

		return "";
	}

	/**
	 * Has the account been verified with OAuth or need a re-verification
	 *
	 * @param string $account
	 * @return string
	 *
	 */
	protected function IsVerified($account=""){
				
		if(!empty($account)){		
			
			$ret = (isset($this->verified[$account]) && $this->verified[$account]===true ? true : false);
			

			return $ret;

		}

		return false;
	}
	

	/**
	 * Connect to a twitter account using OAuth
	 *
	 * @param string $account
	 * @returns object
	 *
	 */
	protected function Connect($account){

		

		$accessToken		= $this->GetAccessToken($account);
		$accessTokenSecret	= $this->GetAccessTokenSecret($account);

		if(!empty($accessToken) && !empty($accessTokenSecret)){			

			$oauth = new strictlyTwitterOAuth(APP_CONSUMER_KEY, APP_CONSUMER_SECRET, $accessToken, $accessTokenSecret);

			// set a useragent which idenitfies the plugin to Twitter
			$oauth->useragent = $this->plugin_name . "v" . $this->version . "." . $this->build . " - " . $this->company .  " ". $this->website;

			// return the object
			return $oauth;
		}else{
			// log a failure message

			$errmsg = sprintf(__("Connection to Twitter account %s aborted. Invalid Access Tokens were supplied.","strictlytweetbot"),$account);
			

			$this->AddMessage($errmsg);

			return false;
		}
	}

	/**
	 * Tests a Twitter connection to ensure its valid
	 *
	 * @param object $oauth
	 * @returns boolean
	 *
	 */
	protected function TestConnection(&$oauth,$account_name=""){

		//ShowTweetBotDebug("IN TestConnection $account_name");

		if(is_object($oauth)){	
			
			$credentials = $oauth->get("account/verify_credentials");
		
			// ensure we got a valid response status code
			if ($oauth->http_code == '200') {

				
				// if we get back a json string try to convert to an object
				if(is_string($credentials)){	
					
					//ShowTweetBotDebug("credentials = " . $credentials);

					$credentials = json_decode($credentials);
				}

				//ShowTweetBotDebug($credentials);

				if(is_object($credentials)){

					// if an account name was passed to us check that the screen name matches it
					if(!empty($account_name)){
				
						//ShowTweetBotDebug("check whether the account name of '$account_name' == '" . $credentials->screen_name . "'??");

						if(strtolower($account_name) == strtolower($credentials->screen_name)){			
		
							//ShowTweetBotDebug("MATCH");

							return true;
						}else{

							//ShowTweetBotDebug("NO MATCH");

							$this->AddMessage(sprintf(__("The Connected Twitter Account Screenname %s does not match the expected value of %s","strictlytweetbot"),$credentials->screen_name,$account_name));

							return false;
						}
					}

					return true;

				}

				$this->AddMessage(__("The response from Twitter could not be understood","strictlytweetbot"));

				return false;
			}else if($credentials->http_code == '401') {
				$this->AddMessage(__("Twitter account could not be authorised","strictlytweetbot"));


				return false;
			}else{
				$this->AddMessage(sprintf(__("Twitter connection failed with the following error %s","strictlytweetbot"),$oauth->http_header["status"]));

				return false;

			}
		}

		return false;
	}


	/**
	 * Add a new message to the global message cache
	 *
	 * @param string $message
	 *
	 */
	protected function AddMessage($message){
		// Add the current datetime to the front of the message
		$log_message = date('Y-M-d H:i:s') . " - " . $message;
		$this->messages[] = $log_message;     
	}

	/**
	 * Saves any messages related to the twitter postings to the database
	 *
	 */
	protected function SaveMessages(){
		
		// save twitter messages to database
		update_option('strictlytweetbot_messages',$this->messages);
	}

	/**
	 * Outputs any Twitter related messages 
	 *
	 */
	protected function OutputMessages(){
		
		$output = "";

		// save twitter messages to database
		$messages = get_option('strictlytweetbot_messages');

		if(is_array($messages)){

			foreach($messages as $msg){
				$output .= "<p>" . $msg . "</p>";
			}

		}

		if(empty($output)){
			$output = __("No Current Messages","strictlytweetbot");
		}

		return $output;
	}
	
	/***
	 * @param string $tweet
	 * returns string
	 **/
	protected function HasTags($tweet)
	{
		ShowTweetBotDebug("does $tweet have #hashtags");
		
		$c = 0;

		preg_match_all("@((?:^|\s)(#\w+)(?:\s|$))+?@",$tweet,$matches,PREG_SET_ORDER);
		
		if($matches){
			$c = count($matches);
			ShowTweetBotDebug("we have $c hash tags");
		}

		return $c;
			
	}
       
	/**
	 * @param string $tweet
	 * @param string $tags
	 * @return string tweet
	 **/
	protected function ReplaceTitleTags($tweet,$tags)
	{
		ShowTweetBotDebug("IN ReplaceTitleTags $tweet with $tags");

		if(!empty($tags)){
			
			$tags = explode(" ",$tags);

			ShowTweetBotDebug("loop through tags");

			foreach($tags as $tag)
			{
				if(!empty($tag))
				{
					$tag = preg_quote($tag);
					$tag = str_replace("#","",$tag);

					ShowTweetBotDebug("check for $tag in $tweet");
					
					// if the tag is not already in the tweet as a #hashtag replace the first occurrence in the tweet of the word with a #+tag
					if(stripos($tweet,"#".$tag)===false){
						
						ShowTweetBotDebug("replace word $tag with #" . $tag);

						$tweet = preg_replace("@\b" . $tag . "\b@i" ,"#".$tag,$tweet,1);
					}
				}
			}
		}

		ShowTweetBotDebug("RETURN $tweet");
		
		return $tweet;
	}

	/**
	 * add hash tags to tweet
	 *	
	 * @param string $tweet
	 * @param string $hashtags	
	 * @return string
	 */
	protected function AddTags($tweet,$hashtags){		
		
		ShowTweetBotDebug("IN add tags to $tweet - tags = $hashtags");

		$orig_tweet = str_replace("%hashtags%","",$tweet);

		// how much room do we have left
		$len = TWEETMAXLEN - strlen($orig_tweet);	

		if($len < 3){
			return $orig_tweet;
		}


		$tags = "";

		if (strpos($hashtags, " ") !== false) {
			$hashtags_array = explode(" ", $hashtags);

			// loop through all tags and only add those that will fit into our gap
			foreach ($hashtags_array as $hashtag) {
				
				if(!preg_match("@" . preg_quote($hashtag) . "@i",$tweet)){
				
					//ShowTweetBotDebug("can add " . $hashtag . " as its not in there already");

					if(strlen(trim($tags . " " . $hashtag)) <= $len){
						
						ShowTweetBotDebug("len <= $len so add " . $hashtag);

						$tags = trim($tags . " " .$hashtag);
					}
				}else{
					ShowTweetBotDebug("$hashtag already in tweet this hash tag $hashtag so ignore");
				}
			}

			if(!preg_match("@%hashtags%@",$tweet)){
				ShowTweetBotDebug("no placeholder in tweet so add to end");
				$tweet = trim($tweet) . " " . $tags;
			}else{
				ShowTweetBotDebug("got placeholder so replace it with $tags");
				// replace %hashtags% with our tags
				$tweet = str_replace("%hashtags%",$tags,$tweet);
			}

			ShowTweetBotDebug("RETURN $tweet len of " . strlen($tweet));
		}else if(strlen(trim($hashtags))<=$len){
			// our tags fit
			$tweet = str_replace("%hashtags%",$hashtags,$tweet);
		}else{
			// nothing fits
			$tweet = str_replace("%hashtags%","",$tweet);
		}

		// safety check
		if(strlen($tweet) > TWEETMAXLEN){
			// too long return original
			return $orig_tweet;
		}	

		// return formatted tweet with hash tags
		return $tweet;
	}


	/**
	* Removes duplicate hash tags
	*
	* @param string $hashtags
	* @return string
	*/
	protected function ReplaceDuplicateTags($hashtags)
	{
		ShowTweetBotDebug("IN ReplaceDuplicateTags remove duplicate hashtags from $hashtags");

		$newtags = "";

		if(!empty($hashtags)){
			
			$arr = explode(" ",$hashtags);
			foreach($arr as $a)
			{
				$a = trim($a);

				if(stripos($newtags,$a)!==false){
					ShowTweetBotDebug("this hashtag $a is already in $newtags - so dont re-add");
				}else{
					// add it with a space on end
					$newtags .= $a . " ";
				}
			}

			if(!empty($newtags)){
				$newtags = trim($newtags);
			}
		}

		return $newtags;
	}

	/**
	 * formats the tweet by replacing the placeholders with their correct values
	 *	
	 * @param string $format
	 * @param string $url
	 * @param string $title
	 * @param string $hashtags
	 * @return string
	 */
	protected function FormatTweet($format,$url,$title,$hashtags){
			
		$title	= html_entity_decode($title, ENT_COMPAT, 'UTF-8');
		
		ShowTweetBotDebug("IN FormatTweet $format ,$url, $title, $hashtags MaxLen is " . TWEETMAXLEN);

		$hashtags = $this->ReplaceDuplicateTags($hashtags);


		$title		= trim($title);
		$title2		= $this->ReplaceTitleTags($title,$hashtags);
		$url		= trim($url);
		$hashtags	= trim($hashtags);

		ShowTweetBotDebug("title = $title title2 = $title2");

		$c = $this->HasTags($title2);

		if($c > 2){
			ShowTweetBotDebug("more than 2 tags already in title");

			$temp = str_replace("%title%",$title2,$format);
			$temp = str_replace("%url%",$url,$temp);
			$temp = str_replace("%hashtags%","",$temp);

			//ShowTweetBotDebug("len of $temp is " . strlen($temp) . " we allow a max of " . TWEETMAXLEN . " chars");

			// if it fits then we are ok
			if(strlen($temp) <= TWEETMAXLEN){
				ShowTweetBotDebug("RETURN $temp len of " . strlen($temp) . " so clean and return");

				$tweet = $this->TrimTweet($tweet);

				// all good
				return $temp;
			}
		}else if($c > 0){
			ShowTweetBotDebug("we have more than 0 tags we have $c tags already in title");
			
			$temp = str_replace("%title%",$title2,$format);
			$temp = str_replace("%url%",$url,$temp);
			$temp = str_replace("%hashtags%","",$temp);


			if(strlen($temp) <= (TWEETMAXLEN-15)){
				
				ShowTweetBotDebug("len is less than " . (TWEETMAXLEN-15) . " can we add some more in?");

				// see if we can squeeze some tags in
				$tweet = $this->AddTags($temp,$hashtags);

				$tweet = $this->TrimTweet($tweet);

				ShowTweetBotDebug("RETURN $tweet with a len of " . strlen($tweet));

				return $tweet;
			}
		}

		//ShowTweetBotDebug("does standard stuff len to reduce size");

		// try a replacement of all desired values first
		$temp = str_replace("%title%",$title,$format);
		$temp = str_replace("%url%",$url,$temp);
		$temp = str_replace("%hashtags%",$hashtags,$temp);

		// if it fits then we are ok
		if(strlen($temp) <= TWEETMAXLEN){
			ShowTweetBotDebug("$temp len " . strlen($temp) . " is less than " . TWEETMAXLEN . " so clean and RETURN");
			
			$tweet = $this->TrimTweet($tweet);

			// all good
			return $temp;
		}

		// otherwise we have to leave something out so try for title and url first
		$temp = str_replace("%title%",$this->ReplaceTitleTags($title,$hashtags),$format);
		$temp = str_replace("%url%",$url,$temp);
		
		// if those two fit
		if(strlen($temp) <= TWEETMAXLEN){
			
			ShowTweetBotDebug("$temp len " . strlen($temp) . " is less than " . TWEETMAXLEN . " so can we add some tags to it?");

			// see if we can squeeze some tags in
			$tweet = $this->AddTags($temp,$hashtags);

			//ShowTweetBotDebug("could we add some #hash tags to $tweet ?");

			$tweet = $this->TrimTweet($tweet);

			return $tweet;

		}else{
			// does the actual title itself contain words to convert to hash tags?
			
			// if by removing the %hashtags% placeholder brings it under 140
			$temptweet = str_replace("%hashtags%","",$temp);

			if(strlen($temptweet) <= TWEETMAXLEN){
				$tweet = $this->ReplaceTitleTags($temptweet,$hashtags);

				$c = preg_match("@(\b#\w+\)+b@",$temptweet);
				
				ShowTweetBotDebug("there are $c #hashtags in $temptweet");

				if($c > 1){
					
					$temptweet = $this->TrimTweet($temptweet);

					// we have more than 1 word in title converted to a hash tag
					return $temptweet;
				}
			}
		}

		ShowTweetBotDebug("cut title in half");
		
		// cut the title in half
		$len		= strlen($temp) -TWEETMAXLEN;
		$titlelen	= strlen($title);

		if($len > ceil($titlelen/2)+3){
			// scrub the title
			$tweet = str_replace("%url%",$url,$temp);
			$tweet = str_replace("%hashtags%",$hashtags,$temp);

			$len = strlen($tweet);

			if($len > TWEETMAXLEN){
				$tweet = substr($tweet,0,TWEETMAXLEN-3);

				// if the last letter is a space we can just add our suffix otherwise add some dots
				if(preg_match("/\w$/",$tweet)){
					$tweet .= "...";
				}
			}
		}else{		

			$title_trim = substr($title, 0, ceil($titlelen / 2)).'...';
			$title_trim = $this->ReplaceTitleTags($title_trim,$hashtags);

			$temp = str_replace("%title%",$title_trim,$format);
			$temp = str_replace("%url%",$url,$temp);				

			if(strlen($temp) <= TWEETMAXLEN){
				// see if we can squeeze some tags in
				$tweet = $this->AddTags($temp,$hashtags);
			}else{
				$tweet = substr($temp,0,(TWEETMAXLEN-3));

				// if the last letter is a space we can just add our suffix otherwise add some dots
				if(preg_match("/\w$/",$tweet)){
					$tweet .= "...";
				}
			}
		}
		
		ShowTweetBotDebug("clean up tweet $tweet if we need to");

		$tweet = $this->TrimTweet($tweet);

		ShowTweetBotDebug("RETURN $tweet with a len of " . strlen($tweet));
		

		return $tweet;
	}
	
	/**
	 * cleans up any long tweets by removing hash tags
	 *
	 * @param string $tweet
	 * @return string
	 */
	protected function TrimTweet($tweet)
	{
		
		ShowTweetBotDebug("IN TrimTweet $tweet len is " . strlen($tweet));

		if(strlen($tweet) < 139)
		{
			ShowTweetBotDebug("less than 139 chars long so return it now");
			return $tweet;
		}
		

		ShowTweetBotDebug("current len of $tweet is " . strlen($tweet));

		if(strlen($tweet) >= 140){
			// remove last hash tag if we can
			ShowTweetBotDebug("remove last #tag if we can");

			$tweet = preg_replace("@(^[\s\S]+?\s)(#[a-z]+?$)@i","$1",$tweet);
			
			//ShowTweetBotDebug("shortened it by removing trailing hash tag to $tweet");

			if(strlen($tweet) > 139)
			{
				$tweet = substr($tweet,0,139);

				ShowTweetBotDebug("shortened it again to 139 chars $tweet");
				
			}				
		}

		//ShowTweetBotDebug("replace broken %hashtags% params");

		// replace two letter words before dots...
		$tweet = preg_replace("@(\s\w{1,2})(\.+)@"," $2",$tweet);
		
		// replace broken placeholder tags %hashtag% %url% %title%
		$tweet = preg_replace("@\s%[hut]\w*?$@","",$tweet);
		
		$tweet = trim($tweet);

		ShowTweetBotDebug("RETURN from TrimTweet with $tweet len of " . strlen($tweet));

		return $tweet;
	}

	/**
	 * returns the account from the key
	 *	
	 * @param string $key
	 * @return string
	 */
	protected function GetAccountFromKey($saved_key){	

		if(!empty($saved_key) && is_array($this->saved_keys)){

			foreach($this->saved_keys as $key=>$val){			
				
				if($val == $saved_key){					

					return $key;
				}
			}
		}
		return false;

	}

	/**
	 * deletes all accounts
	 *	
	 * @param string $account
	 * @return bool
	 */
	protected function DeleteAllAccounts(){

		unset($this->accounts);
		unset($this->account_names);
		unset($this->access_token_secrets);
		unset($this->access_tokens);
		unset($this->verified);
		unset($this->defaulttags);
		unset($this->formats);
		unset($this->active);
		unset($this->tagtypes);
		unset($this->contentanalysis);
		unset($this->contentanalysistype);
		unset($this->saved_keys);
		unset($this->extra_querystring);
		unset($this->ignoreterms);
		unset($this->textshrink);
		unset($this->tweetshrink);

		
		$strictlytweet_options	= array(
									"accounts" => $this->accounts,
									"account_names" => $this->account_names,
									"access_token_secrets" => $this->access_token_secrets,
									"access_tokens" => $this->access_tokens,
									"verified" => $this->verified,
									"defaulttags" => $this->defaulttags,
									"formats" => $this->formats,
									"active" => $this->active,
									"tagtypes" => $this->tagtypes,										
									"bitlyAPIkey" => $this->bitlyAPIkey,
									"bitlyAPIusername" => $this->bitlyAPIusername,
									"bitlyAPI" => $this->bitlyAPI,
									"contentanalysis" => $this->contentanalysis,
									"contentanalysistype" => $this->contentanalysistype,
									"saved_keys" => $this->saved_keys,
									"extra_querystring" => $this->extra_querystring,
									"ignoreterms" => $this->ignoreterms,									
									"textshrink" => $this->textshrink,
									"tweetshrink" => $this->tweetshrink
									);

		// save our data to the wordpress database
		update_option('strictlytweetbot_options', $strictlytweet_options);

	}

	/**
	 * deletes an account
	 *	
	 * @param string $account
	 * @return bool
	 */
	protected function DeleteAccount($key){

		

		if(!empty($key)){

			// get the account from the key - don't really need this now I have put code in to prevent duplicate account names from
			// being added however there may be future reasons so I'll continue to use a surrogate key

			$account = $this->GetAccountFromKey($key);

			// update each array then re-save
			// create array to store results

			unset($this->accounts[$account]);
			unset($this->account_names[$account]);
			unset($this->access_token_secrets[$account]);
			unset($this->access_tokens[$account]);
			unset($this->verified[$account]);
			unset($this->defaulttags[$account]);
			unset($this->formats[$account]);
			unset($this->active[$account]);
			unset($this->tagtypes[$account]);
			unset($this->contentanalysis[$account]);
			unset($this->contentanalysistype[$account]);
			unset($this->saved_keys[$account]);
			unset($this->extra_querystring[$account]);
			unset($this->ignoreterms[$account]);
			unset($this->textshrink[$account]);
			unset($this->tweetshrink[$account]);

			$strictlytweet_options	= array(
										"accounts" => $this->accounts,
										"account_names" => $this->account_names,
										"access_token_secrets" => $this->access_token_secrets,
										"access_tokens" => $this->access_tokens,
										"verified" => $this->verified,
										"defaulttags" => $this->defaulttags,
										"formats" => $this->formats,
										"active" => $this->active,
										"tagtypes" => $this->tagtypes,										
										"bitlyAPIkey" => $this->bitlyAPIkey,
										"bitlyAPIusername" => $this->bitlyAPIusername,
										"bitlyAPI" => $this->bitlyAPI,
										"contentanalysis" => $this->contentanalysis,
										"contentanalysistype" => $this->contentanalysistype,
										"saved_keys" => $this->saved_keys,
										"extra_querystring" => $this->extra_querystring,
										"ignoreterms" => $this->ignoreterms,
										"textshrink" => $this->textshrink,
										"tweetshrink" => $this->tweetshrink
									);

			
			

			// save our data to the wordpress database
			update_option('strictlytweetbot_options', $strictlytweet_options);

		}


	}

	/**
	 * get saved options otherwise use defaults
	 *	 
	 * @return array
	 */
	protected function GetOptions(){		

		ShowTweetBotDebug("IN GetOptions");

		$this->uninstall				= get_option('strictlytweetbot_uninstall');

		$this->install_date				= get_option("strictlytweetbot_install_date");		

		$this->cacheuseragent			= get_option("strictlytweetbot_cacheuseragent");

		if(!isset($this->cacheuseragent) || empty($this->cacheuseragent)){

			$this->cacheuseragent		= $this->defaultuseragent;

			ShowTweetBotDebug("cache user agent is null set to default user agent = " . $this->cacheuseragent);
		}else{
			ShowTweetBotDebug("cache user agent is NOT null = " . $this->cacheuseragent);
		}

		$this->loadpagebeforetweeting	= get_option("strictlytweetbot_loadpagebeforetweeting");


		// we store everything in one array - extract and then reassemble
		$strictlytweetoptions			= get_option("strictlytweetbot_options");		

		if(is_array($strictlytweetoptions)){
		
			$this->accounts				= $strictlytweetoptions["accounts"];
			$this->account_names		= $strictlytweetoptions["account_names"];
			$this->access_token_secrets	= $strictlytweetoptions["access_token_secrets"];
			$this->access_tokens		= $strictlytweetoptions["access_tokens"];
			$this->verified				= $strictlytweetoptions["verified"];
			$this->defaulttags			= $strictlytweetoptions["defaulttags"];
			$this->formats				= $strictlytweetoptions["formats"];
			$this->active				= $strictlytweetoptions["active"];
			$this->tagtypes				= $strictlytweetoptions["tagtypes"];
			$this->bitlyAPIkey			= $strictlytweetoptions["bitlyAPIkey"];
			$this->bitlyAPIusername		= $strictlytweetoptions["bitlyAPIusername"];
			$this->bitlyAPI				= $strictlytweetoptions["bitlyAPI"];
			$this->contentanalysis		= $strictlytweetoptions["contentanalysis"];
			$this->contentanalysistype	= $strictlytweetoptions["contentanalysistype"];
			$this->saved_keys			= $strictlytweetoptions["saved_keys"];
			$this->extra_querystring	= $strictlytweetoptions["extra_querystring"];
			$this->ignoreterms			= $strictlytweetoptions["ignoreterms"];			
			$this->tweetshrink			= $strictlytweetoptions["tweetshrink"];
			$this->textshrink			= $strictlytweetoptions["textshrink"];			
				
		}
		
		ShowTweetBotDebug("got options");
	}

	/**
	 * save options
	 *	 	 
	 */
	protected function SaveOptions(){		

		ShowTweetBotDebug("IN SaveOptions");

		if (current_user_can('manage_options')) {

			//ShowTweetBotDebug("look at our arrays");	
			

			// create array to store results
			$strictlytweet_options	= array(
										"accounts" => $this->accounts,
										"account_names" => $this->account_names,
										"access_token_secrets" => $this->access_token_secrets,
										"access_tokens" => $this->access_tokens,
										"verified" => $this->verified,
										"defaulttags" => $this->defaulttags,
										"formats" => $this->formats,
										"active" => $this->active,
										"tagtypes" => $this->tagtypes,										
										"bitlyAPIkey" => $this->bitlyAPIkey,
										"bitlyAPIusername" => $this->bitlyAPIusername,
										"bitlyAPI" => $this->bitlyAPI,
										"contentanalysis" => $this->contentanalysis,
										"contentanalysistype" => $this->contentanalysistype,
										"saved_keys" => $this->saved_keys,
										"extra_querystring" => $this->extra_querystring,
										"ignoreterms" => $this->ignoreterms,
										"textshrink" => $this->textshrink,
										"tweetshrink" => $this->tweetshrink
										);

			
			

			// save our data to the wordpress database
			update_option('strictlytweetbot_options', $strictlytweet_options);

			// save these by themselves
			update_option('strictlytweetbot_uninstall', $this->uninstall);
			
			if(!isset($this->cacheuseragent)){
				ShowTweetBotDebug("cacheuseragent NOT Set so use default user agent = " .  $this->defaultuseragent);

				$this->cacheuseragent		= $this->defaultuseragent;
			}

			ShowTweetBotDebug("Save strictlytweetbot_cacheuseragent to " .  $this->cacheuseragent);

			update_option('strictlytweetbot_cacheuseragent', $this->cacheuseragent);

			update_option('strictlytweetbot_loadpagebeforetweeting', $this->loadpagebeforetweeting);
	

			$this->SaveMessages();
			
		
		}
	}

	/**
	 * Incrememnets a name by adding numbers to the end of it
	 *
	 */
	protected function GetUniqueName($name){

		preg_match("@(^.+\-)(\d+)$@",$name,$match);

		if(!$match){
			$name = $name . "-1";
		}else{
			$inc = intval($match[2])+1+"";
			$name = $match[1] . $inc;
		}

		// loop through names
		foreach($this->accounts as $acc){
			if(strcasecmp($acc,$name)==0){
				return $this->GetUniqueName($name);
			}
		}
		
		// no match return
		return $name;
	}

	/**
	 * Admin page for backend management of plugin
	 *
	 */
	public function AdminOptions(){

		// ensure we are in admin area
		if(!is_admin()){
			die("You are not allowed to view this page");
		}

		// message to show to admin if input is invalid
		$errmsg = $msg		= "";
		
		// wordpress doesn't seem to set any FORM action values which means the querystring stays as it was when the page last loaded
		// this is obviously wrong as we may still have parameters from the last action such as the del=1 (delete item)
		// therefore I only carry out a delete if the submit button hasn't been clicked otherwise we will be running unneccessary code all the time


		if ( !$_POST['cmdSubmit'] && $_GET['del'] == "1"){
		
			$key = $_GET['key'] ;

			// check nonce - not AJAX but so what
			check_ajax_referer('strictly-tweetbot-nonce');

			$this->DeleteAccount($key);
		}

		// handle a purge of all accounts
		if( $_POST['cmdDelete'] ){

			$this->DeleteAllAccounts();

		}

		// test everything is setup correctly and twitter accounts can be accessed
		if( $_POST['cmdTestConfig'] ){

			$msg = $this->TestConfig();

		}

		// if our option form has been submitted then save new values
		if ( $_POST['cmdSubmit'] ){

			

			// check nonce
			check_admin_referer("adminform","strictlytweetbotchecknonce");

			// save bit.ly details
			
			$this->uninstall				= (bool)strip_tags(stripslashes($_POST['uninstall']));
			$this->loadpagebeforetweeting	= (bool)strip_tags(stripslashes($_POST['loadpagebeforetweeting']));
			$this->cacheuseragent			= strip_tags(stripslashes($_POST['cacheuseragent']));

			if(isset( $this->cacheuseragent )){
				ShowTweetBotDebug("cacheuseragent is SET get from posted data cacheuseragent = " . $this->cacheuseragent);
			}else{
				ShowTweetBotDebug("cacheuseragent is NOT SET get from posted data cacheuseragent = " . $this->cacheuseragent);
			}
			

			$this->bitlyAPIkey				= strip_tags(stripslashes($_POST['tweetbot_bitlyAPIkey']));
			$this->bitlyAPIusername			= strip_tags(stripslashes($_POST['tweetbot_bitlyAPIusername']));	
			$this->bitlyAPI					= strip_tags(stripslashes($_POST['tweetbot_bitlyAPI']));	

			// get list of accounts we may have deleted (added to DOM then removed)
			$deleted						= "," . strip_tags(stripslashes($_POST['account_delete_counter']));

			$accounts						= strip_tags(stripslashes($_POST['account_counter']));
			
			$updated						= 0;

			if(is_numeric($accounts)){

				$saveerr = false;
				
				for($x = 1; $x<=$accounts; $x++){
					
					$recerr = false;

					// check its not in list of deleted
					if(strpos($deleted,",".$x.",") === false){

						$account						= strip_tags(stripslashes($_POST['tweetbot_account_' . $x]));
						$account_saved_key				= strip_tags(stripslashes($_POST['tweetbot_key_' . $x]));					

						if(is_array($this->accounts) && count($this->accounts) > 0){

							// new accounts won't have a saved key and we only need to check new accounts for dupes
							
							if( $this->GetAccountFromKey($account_saved_key) === false){

								// accounts must be unique as we use the name as the "key" to retrieve all other data
								// if the account name isn't unique we don't save or even load up other values as it could mean
								// overwriting an existing account. So for new accounts we check for duplicate names
								if( (bool) (in_array(strtolower($account), array_map('strtolower', array_keys($this->accounts))))  ){

									// modify the account name as we still update the array even if we are not saving and we need
									// to ensure the account name is unique

									$newaccount	= $this->GetUniqueName($account);								

									$errmsg .= sprintf(__('<p>Account Names must be unique. There is already an Account with the name %s. The Account has been changed to %s.</p>','strictlytweetbot'),$account,$newaccount);
																		
									$saveerr	= true; // show error message but dont prevent the save key from being udpated
									$account	= $newaccount;

									
								}
							}
						}


						$account_name					= strip_tags(stripslashes($_POST['tweetbot_account_name_' . $x]));
						$account_pin					= strip_tags(stripslashes($_POST['tweetbot_pin_' . $x]));								
						$account_request_token			= strip_tags(stripslashes($_POST['tweetbot_request_token_' . $x]));
						$account_request_token_secret	= strip_tags(stripslashes($_POST['tweetbot_request_token_secret_' . $x]));
						$account_access_token			= strip_tags(stripslashes($_POST['tweetbot_access_token_' . $x]));
						$account_access_token_secret	= strip_tags(stripslashes($_POST['tweetbot_access_token_secret_' . $x]));
						$account_defaulttags			= strip_tags(stripslashes($_POST['tweetbot_defaulttags_' . $x]));
						$account_format					= strip_tags(stripslashes($_POST['tweetbot_format_' . $x]));
						$account_active					= (bool)strip_tags(stripslashes($_POST['tweetbot_active_' . $x]));
						//$account_verified				= (bool)strip_tags(stripslashes($_POST['tweetbot_verified_' . $x]));
						$account_tagtype				= strip_tags(stripslashes($_POST['tweetbot_tagtype_' . $x]));
						$account_contentanalysis		= strip_tags(stripslashes($_POST['tweetbot_contentanalysis_' . $x]));
						$account_contentanalysistype	= strip_tags(stripslashes($_POST['tweetbot_contentanalysistype_' . $x]));
						$account_extra_querystring		= strip_tags(stripslashes($_POST['tweetbot_extra_querystring_' . $x]));
						$account_ignoreterms			= strip_tags(stripslashes($_POST['tweetbot_ignoreterms_' . $x]));
						$account_textshrink				= (bool)strip_tags(stripslashes($_POST['tweetbot_textshrink_' . $x]));
						$account_tweetshrink			= (bool)strip_tags(stripslashes($_POST['tweetbot_tweetshrink_' . $x]));

						// load array
						$this->accounts[$account]				= $account;
						$this->account_names[$account]			= $account_name;
						$this->access_tokens[$account]			= $account_access_token;
						$this->access_token_secrets[$account]	= $account_access_token_secret;
						$this->defaulttags[$account]			= $account_defaulttags;
						$this->formats[$account]				= $account_format;
						$this->active[$account]					= $account_active;						
						$this->tagtypes[$account]				= $account_tagtype;
						$this->contentanalysis[$account]		= $account_contentanalysis;
						$this->contentanalysistype[$account]	= $account_contentanalysistype;
						$this->extra_querystring[$account]		= $account_extra_querystring;
						$this->ignoreterms[$account]			= $account_ignoreterms;
						$this->textshrink[$account]				= $account_textshrink;
						$this->tweetshrink[$account]			= $account_tweetshrink;

						
						// validate						
						
						if(empty($account)){

							$errmsg .= __('<p>Each Account must have a unique name.</p>','strictlytweetbot');
							$recerr = true;
						}
						if(empty($account_name)){

							$errmsg .= __('<p>The Twitter Account username must be entered.</p>','strictlytweetbot');
							$recerr = true;
						}
						if(empty($account_format)){

							$errmsg .= __('<p>The message must have a format.</p>','strictlytweetbot');
							$recerr = true;
						}

						

						// only valid records get a saved key
						if(!$recerr){
							

							$this->saved_keys[$account]				= $account_saved_key;
						}

						// has account already been verified? Might override later if a new pin was supplied
						if($this->IsVerified($account) && !empty($account_access_token) && !empty($account_access_token_secret)){
							$this->verified[$account]		= true;
						}else{
							

							// do we have an account with the same twitter account? If so we can use the same verification details
							// as there is no need to reverify the same account more  than once

							$account_name_lower = strtolower($account_name);

							// set it to false now and if we find a verified account with the right details override
							$this->verified[$account]		= false;

							foreach($this->accounts as $acc){

								if(strtolower($this->account_names[$acc]) == $account_name_lower){

									if(($this->verified[$acc]) && !empty($this->access_tokens[$acc]) && !empty($this->access_token_secrets[$acc])){
									
										// share these details
										$this->access_tokens[$account]			= $this->access_tokens[$acc];
										$this->access_token_secrets[$account]	= $this->access_token_secrets[$acc];
										$this->verified[$account]				= true;

										break;
									}
								}
							}							
						}

						if(!$recerr){						

							// if we have been given a new pin code then verfify it
							// this might be a new account or one that has been deactivated and needs relinking to the application
							// we may have a pin AND an existing access token/secret pair. But if a pin is supplied ALWAYS reverify

							if(!empty($account_pin) && !empty($account_request_token) && !empty($account_request_token_secret)){
															
								$oauth = new strictlyTwitterOAuth(APP_CONSUMER_KEY,APP_CONSUMER_SECRET, $account_request_token, $account_request_token_secret);

								// Generate access token by providing PIN for Twitter
								$request = $oauth->getAccessToken(NULL, $account_pin);							

								$account_access_token			= $request['oauth_token'];
								$account_access_token_secret	= $request['oauth_token_secret'];

								$authmsg = implode("",$request);

								if(!empty($account_access_token) && !empty($account_access_token_secret)){

									// account verified
									$this->AddMessage( sprintf(__("Twitter Account: %s verified","strictlytweetbot"),$account_name));

									$this->verified[$account]		= true;

									// put into array
									$this->access_tokens[$account]			= $account_access_token;
									$this->access_token_secrets[$account]	= $account_access_token_secret;
								}else{
									// account not verified

									if(stripos($authmsg,"Invalid / expired Token") !== false){
										$this->AddMessage( sprintf(__("Twitter Account: %s could not be verified due to an expired token. Please re-verify the account by obtaining a new PIN code.","strictlytweetbot"),$account_name));
									}else{									
										$this->AddMessage( sprintf(__("Twitter Account: %s could not be verified","strictlytweetbot"),$account_name));
									}

									$this->verified[$account]		= false;
								}
								
							}
							
							$updated++;
						}
					}
					// flag save error so we show our messages
					if($recerr){
						$saveerr = true;
					}
				}
				
			}

			

			// if we had no validation errors then save back to the DB

			if(!$saveerr){
				// save to the DB
				$this->SaveOptions();

				$msg .= __('<p>Options Saved.</p>','strictlytweetbot');

				if($accounts>0){
					$msg .= sprintf(__('<p>%d Twitter Accounts were configured correctly.</p>','strictlytweetbot'),$updated);
				}
			}else{
				$errormsg = sprintf(__('<p>Configuration Error.</p>%s','strictlytweetbot'),$errmsg);
			}
		}else{

			// get saved options
			$this->GetOptions();			
		}
		
		
		// create a nonce for the JS calls 
		$nonce = wp_create_nonce("strictly-tweetbot-nonce"); 
		
		echo	'<style type="text/css">
				#StrictlySitemapAdmin{
					overflow:auto;
				}
				div.TwitterMessages{
					background:white;
					color:navy;
					padding: 5px 5px 5px 5px;
					max-height: 250px;
					overflow:auto;
					font-weight:bold; 
				}
				div.StrictlyMsg{					
					padding:10px; 
					font-weight:bold; 
					color:green;
				}
				div.warn{
					font-weight:bold;
					color:#C00;
					padding:10px; 
				}				
				div.hide{
					display:none;
				}
				div.show{
					display:block;
				}

				#StrictlyTweetbotAdmin h3 {
					font-size:12px;
					font-weight:bold;
					line-height:1;
					margin:0;
					padding:7px 9px;
				}
				div.inside{
					padding: 10px;
				}
				div.TwitterAccount{
					width:	700px;
				}
				div.TwitterAccount label{
					display:inline-block;
					width: 250px;
				}
				div.TwitterAccount input{
					display:inline-block;					
				}
				div.TwitterAccount fieldset.options{
					padding-left:5px;
				}
				div.bitly input{
					display:inline-block;					
				}
				div.bitly label{
					display:inline-block;
					width: 250px;
				}
				.notes{
					display:block;
					font-size: 80%;
					margin: 5px 0 10px 0;
				}
				#StrictlyTweetbotAdmin input[type="radio"] {
					margin: 5px;
				}
				.delaccount{
					margin-right: 120px;
					float:right;
				}
			</style>
			
			<script type="text/javascript">

				TwitterAccount = {

					addaccount : function(){

						var c = document.getElementById("account_counter").value;

						if(!c || c=="" || c=="0"){
							c = 1;
						}else{
							c++;
						}
						
						document.getElementById("account_counter").value = c;
						
						var key = new Date().getTime();

						var html = "<h3 class=\"hndle\">'.__('TweetBot Options', 'strictlytweetbot').'</h3>\n"+					
									"<div class=\"inside\">\n"+
									"<div class=\"TwitterAccount\" id=\"Account" + c + "\"><fieldset class=\"options\">\n"+
									"<input type=\"hidden\" name=\"tweetbot_key_" + c + "\" id=\"tweetbot_key_" + c + "\" value=" + key + " />\n"+
									"<img class=\"delaccount\" src=\"' .$this->pluginurl . 'cross.gif\" alt=\"'.__('Cross','strictlytweetbot').'\" title=\"'.__('Delete Account','strictlytweetbot').'\" onclick=\"TwitterAccount.deleteaccount(\'" + c + "\',\'\');\" />\n"+
									"<h4 id=\"hx_" + c + "\">'.__('Twitter Account Username','strictlytweetbot').'</h4>\n"+			
									"<div>\n"+
										"<label for=\"tweetbot_account_" + c + "\">'.__('Auto Tweet Account Name', 'strictlytweetbot').'</label><input type=\"text\" name=\"tweetbot_account_" + c + "\" id=\"tweetbot_account_" + c + "\" value=\"\" size=\"50\" maxlength=\"50\" />\n"+
										"<span class=\"notes\">'.__('A unique descriptive name for this automatic tweet.', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_account_name_" + c + "\">'.__('Twitter Account Username', 'strictlytweetbot').'</label><input type=\"text\" name=\"tweetbot_account_name_" + c + "\" id=\"tweetbot_account_name_" + c + "\" value=\"\" size=\"50\" maxlength=\"50\" />\n"+
										"<span class=\"notes\">'.__('The Twitter account username / screename that you will be posting to.', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_defaulttags_" + c + "\">'.__('Default Hash Tags', 'strictlytweetbot').'</label><input type=\"text\" name=\"tweetbot_defaulttags_" + c + "\" id=\"tweetbot_defaulttags_" + c + "\" value=\"\" size=\"50\" maxlength=\"140\" />\n"+
										"<span class=\"notes\">'.__('Tags to use if no categories or post tags are available e.g #Sales #WebDev.', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_contentanalysis_" + c + "\">'.__('Content Analysis', 'strictlytweetbot').'</label>\n"+
										"<input type=\"text\" name=\"tweetbot_contentanalysis_" + c + "\" id=\"tweetbot_contentanalysis_" + c + "\" value=\"\" size=\"50\" maxlength=\"150\" />\n"+	
										"<br /><label></label><input type=\"radio\" name=\"tweetbot_contentanalysistype_" + c + "\" id=\"tweetbot_contentanalysistype_all_" + c + "\" value=\"ALL\" />' . __('All Words','strictlytweetbot') . '<input type=\"radio\" name=\"tweetbot_contentanalysistype_" + c + "\" id=\"tweetbot_contentanalysistype_any_" + c + "\" value=\"ANY\" />' . __('Any Word','strictlytweetbot') . '<input type=\"radio\" name=\"tweetbot_contentanalysistype_"  + c + "\" id=\"tweetbot_contentanalysistype_none_" + c + "\" value=\"NONE\"  />' . __('No Words','strictlytweetbot')  . '<input type=\"radio\" name=\"tweetbot_contentanalysistype_"  + c + "\" id=\"tweetbot_contentanalysistype_always_" + c + "\" value=\"ALWAYS\"  />' . __('Always Post','strictlytweetbot') . '<span class=\"notes\">'.__('Choose when to post the tweet. Picking <b>Always</b> will always tweet even with words in the input. Picking <b>All</b> means that all the words have to be in the article for the tweet to go out whilst <b>Any</b> means only one set of words has to exist to tweet. Select <b>None</b> if you don\'t want to tweet if certain words are in the article. Please separate words with a comma e.g Sales Manager, Managing.', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_ignoreterms_" + c + "\">'.__('Ignore List', 'strictlytweetbot').'</label><textarea type=\"text\" name=\"tweetbot_ignoreterms_" + c + "\" id=\"tweetbot_ignoreterms_" + c + "\" style=\"width:100%;height:75px;\" /></textarea>\n"+
										"<span class=\"notes\">'.__('Words to ignore when using categories or post tags as hashtags. Separate each word with a comma e.g President Obama, New York City Library, Paul Smith', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_format_" + c + "\">'.__('Tweet Format', 'strictlytweetbot').'</label><input type=\"text\" name=\"tweetbot_format_" + c + "\" id=\"tweetbot_format_" + c + "\" value=\"\" size=\"50\" maxlength=\"140\" />\n"+
										"<span class=\"notes\">'.__('The format that the tweet will take e.g %title% %url% %hashtags%.', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_extra_querystring_" + c + "\">'.__('Tracking Code', 'strictlytweetbot').'</label><input type=\"text\" name=\"tweetbot_extra_querystring_" + c + "\" id=\"tweetbot_extra_querystring_" + c + "\" value=\"\" size=\"50\" maxlength=\"500\" />\n"+
										"<span class=\"notes\">'.__('Add querystring parameters to the post url such as Google tracking codes before shortening e.g ?__utma=1.1695673470', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_tweetshrink_" + c + "\">'.__('Tweet Shrink Title', 'strictlytweetbot').'</label><input type=\"checkbox\" name=\"tweetbot_tweetshrink_" + c + "\" id=\"tweetbot_tweetshrink_" + c + "\" value=\"true\" />\n"+								
										"<span class=\"notes\">'.__('Use the Tweet Shrink API to shorten the title before posting', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_textshrink_" + c + "\">'.__('Text Shrink Title', 'strictlytweetbot').'</label><input type=\"checkbox\" name=\"tweetbot_textshrink_" + c + "\" id=\"tweetbot_textshrink_" + c + "\" value=\"true\" />\n"+									
										"<span class=\"notes\">'.__('Shorten the title before posting by using converting it to text speak e.g \"See you tonight mate\" would become \"c u 2nite m8\"', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_active_" + c + "\">'.__('Active', 'strictlytweetbot').'</label><input type=\"checkbox\" name=\"tweetbot_active_" + c + "\" id=\"tweetbot_active_" + c + "\" value=\"true\"  />\n"+	
										"<span class=\"notes\">'.__('Enable or disable this account from tweeting.', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"<div>\n"+
										"<label for=\"tweetbot_tagtype_" + c + "\">'.__('Hash Tag Source', 'strictlytweetbot').'</label><input type=\"radio\" name=\"tweetbot_tagtype_" + c + "\" id=\"tweetbot_tagtype_tag\" value=\"tag\" />'.__('Post Tags','strictlytweetbot').'<input type=\"radio\" name=\"tweetbot_tagtype_" + c + "\" id=\"tweetbot_tagtype_category\" value=\"category\" />'.__('Categories','strictlytweetbot').'<input type=\"radio\" name=\"tweetbot_tagtype_" + c + "\" id=\"tweetbot_tagtype_default\" value=\"default\" />'.__('Default','strictlytweetbot').'\n"+
										"<span class=\"notes\">'.__('The source of any Hash Tag values.', 'strictlytweetbot').'</span>\n"+
									"</div>\n"+
									"</fieldset>\n"+
								"</div></div>"; 
						
						var el = document.createElement("div");
						el.className = "postbox";
						el.setAttribute("id","AccountWrapper"+c);
						el.innerHTML = html;
						document.getElementById("TweetAccounts").appendChild(el);

					},

					deleteaccount : function(c,n){
						ShowDebug("IN delete account c = " + c + " n = " + n);

						// if account was added on the fly then it hasnt been saved so we can just remove it again
						if(confirm("' . __('Are your sure you want to remove this Twitter account?','strictlytweetbot') . '")){
							ShowDebug("remove div = hx_"+c);

							if(document.getElementById("hx_"+c)){
								var el = document.getElementById("AccountWrapper"+c);
								ShowDebug("el = " + el.id + " typeof = " + typeof(el));
								el.parentNode.removeChild(el);  
								document.getElementById("account_delete_counter").value = document.getElementById("account_delete_counter").value+c+",";								

								ShowDebug("gone");
							}else{
								ShowDebug("location.href=" + location.href);

								document.forms[0].action=TwitterAccount.CleanURL(location.href) + "&_ajax_nonce=' . $nonce . '&del=1&key="+encodeURIComponent(n);
								document.forms[0].submit();
							}
						}
					},

					CleanURL : function(url){
						
						if(url!=""){
							return url.replace(/&_ajax_nonce=\S+?&del=1&key=[^& ]+$/,"");
						}

					}
				}

				function ShowDebug(m){
					if(typeof(window.console)!="undefined"){
						console.log(m);
					}
				}
			</script>';


		if($this->version_type == "FREE")
		{
			echo '<div class="wrap" id="StrictlyTweetbotAdmin">
					<h2>' . $this->plugin_name . ' Version: '.$this->version . '.' . $this->build.' (free version)</h2>
					<div class="postbox"><div class="inside">
					<p>You are using a free version of the plugin the latest premium version is Version: ' . $this->paid_version . ' which you can purchase on my website at <a target="_blank" href="http://www.strictly-software.com/plugins/strictly-tweetbot">strictly-software.com/plugins/strictly-tweetbot</a> for &pound;25.</p><p>You can also purchase it on my shop at <a href="https://www.etsy.com/uk/shop/StrictlySoftware">etsy.com</a> for the same amount.<p><p>It has extra features such as:<ul><li>The ability to add a querystring to your URL when caching to ensure it is cached.</li><li>Add a time delay after making an HTTP cache request before Tweeting.</li><li>Adding time delays between each tweeted message to prevent Twitter Rushes.</li><li>The ability to force set a maximum Tweet length in admin.</li><li>The option to disable any link with Strictly AutoTags so that tweets are sent on publish and don\'t wait until tagging is complete.</li><li>Extra messages to help with debug and post tweet analysis.</li></ul>
					<p>'.sprintf(__('Please check out %s for the latest news and details of other great plugins and tools.','strictlytweetbot'),'<a href="' . $this->website . '" title="' . $this->company .'">' . $this->company . '</a>').'</p>
					<p>' . __('<strong>Please show your support for Strictly Software and help me to continue providing Wordpress Plugins for free by:</strong>','strictlytweetbot') . '</p>
						 <ul id="supportstrictly">
							<li><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=6427652"><strong>Make a donation on PayPal.</strong></a></li>
							<li><a href="http://www.strictly-software.com/plugins/strictly-tweetbot">Linking to the plugin from your own site or blog so that other people can find out about it.</a></li>
							<li><a href="http://wordpress.org/extend/plugins/strictly-tweetbot/">Give the plugin a good rating on Wordpress.org.</a></li>						
						 </ul>
						 <p>'.__('People who use this plugin also buy my','strictlytweetbot'). ' <a href="http://www.strictly-software.com/plugins/strictly-auto-tags" title="'.__('Strictly AutoTags plugin to automatically add relevant tags to articles which can then be used as #hashtags.','strictlytweetbot').'">'.__('Strictly AutoTags plugin to automatically add relevant tags to articles which can then be used as #hashtags. This is great for Auto-Blogging when content is loaded from feeds automatically.' ,'strictlytweetbot') . '</a></p>
					</div>
				</div>';
		
		}else{

			echo '<div class="wrap" id="StrictlyTweetbotAdmin">
					<h2>' . $this->plugin_name . ' Version: '.$this->version . '.' . $this->build.' (premium version - thank you!)</h2>
					<div class="postbox"><div class="inside">
					<p>You are using a premium version of the plugin which has many extra features that the free version doesn\'t have.</p>
					<p>'.sprintf(__('Please check out %s for the latest news and details of other great plugins and tools.','strictlytweetbot'),'<a href="' . $this->website . '" title="' . $this->company .'">' . $this->company . '</a>').'</p>					
					<p>'.__('People who use this plugin also buy my','strictlytweetbot'). ' <a href="http://www.strictly-software.com/plugins/strictly-auto-tags" title="'.__('Strictly AutoTags plugin to automatically add relevant tags to articles which can then be used as #hashtags.','strictlytweetbot').'">'.__('Strictly AutoTags plugin to automatically add relevant tags to articles which can then be used as #hashtags. This is great for Auto-Blogging when content is loaded from feeds automatically.' ,'strictlytweetbot') . '</a></p>
					</div>
				</div>';
		}


		if(!empty($msg) || !empty($errormsg)){
			echo	'<div class="postbox">
						<h3 class="hndle">'.__('Plugin Information', 'strictlytweetbot').'</h3>';

						if(!empty($msg)){
							echo '<div class="StrictlyMsg">'.$msg.'</div>';
						}
						if(!empty($errormsg)){
							echo '<div class="warn">'.$errormsg.'</div>';
						}
			echo	'</div>';
		}

		// output any messages related to the last lot of twitter posts or configuration issues
		echo	'<div class="postbox">
					<h3 class="hndle">'.__('Twitter Messages', 'strictlytweetbot').'</h3>
					<div class="TwitterMessages">'.$this->OutputMessages() .'</div>
				</div>';



		echo	'<form method="post" action="options-general.php?page=strictly-tweetbot.class.php">
					'. wp_nonce_field("adminform","strictlytweetbotchecknonce",false,false) .'
					<div class="postbox">
						<h3 class="hndle">'.__('TweetBot Options', 'strictlytweetbot').'</h3>					
						<div class="inside">
							<p>'.__('The Strictly TweetBot lets you post specific Tweets to as many Twitter accounts as you require whenever a post is added to your site. This is very useful if you import posts at scheduled intervals as you might not have time to create custom tweets per post. You can format each message differently and you can utilise the categories or tags that reside against the post as #hash tags to help people find your tweets.','strictlytweetbot').'</p><p>'.__('Add the details for each Twitter account you would like to post to and specify the format for the Tweet using the following placeholders:<strong><em>%title% %url%</em></strong> and <strong><em>%hashtags%.</em></strong></p><p>%title% will be replaced with the post title, %url% with the articles permalink (shortened if you provide details for Bit.ly) and %hashtags% will be replaced with either categories, post tags or default hash tags depending on how you have configured the account.','strictlytweetbot').'</p>
						</div>
					</div>


					<div id="TweetAccounts">
					<div class="postbox">
						<h3 class="hndle">'.__('TweetBot Global Options', 'strictlytweetbot').'</h3>					
						<div class="inside">
						<p>'.sprintf(__('This plugin was installed on %s.','strictlytweetbot'),"<strong>".$this->install_date."</strong>").'</p>';
		
		$account_counter = 0;

		if(function_exists('ShowDebugAutoTag')){
			$strictly_auto_tags_active = true;
		}else{
			$strictly_auto_tags_active = false;
		}	

		echo	'<div class="bitly">
					<label for="uninstall">'.__('Uninstall Plugin when deactivated', 'strictlytweetbot').'</label><input type="checkbox" name="uninstall" id="uninstall" value="true" ' . (($this->uninstall) ? 'checked="checked"' : '') . '/>
				</div>
				<div class="bitly">
					<label for="loadpagebeforetweeting">'.__('Make HTTP request to post before Tweeting', 'strictlytweetbot').'</label><input type="checkbox" name="loadpagebeforetweeting" id="loadpagebeforetweeting" value="true" ' . (($this->loadpagebeforetweeting) ? 'checked="checked"' : '') . '/><span class="notes">'.__('If you have a caching system configured correctly you can make an HTTP request to the post before Tweeting so that the post gets cached before any Twitter rush is caused by BOTS hitting it.', 'strictlytweetbot').'</span>
				</div>
				<div class="bitly">
					<label for="cacheuseragent">'.__('HTTP Cache Request User-Agent', 'strictlytweetbot').'</label>
					<input type="text" name="cacheuseragent" id="cacheuseragent" value="' . esc_attr($this->cacheuseragent) . '" size="150" maxlength="150" /><span class="notes">'.__('If you are making HTTP requests before tweeting to try and cache your posts then you can use a different user-agent to identify your requests in your log files or add rules in your htaccess files related to them. Remeber this caching method will only work if your caching tool is setup correctly.', 'strictlytweetbot').'</span>
				</div>';

		if($strictly_auto_tags_active){
		
			echo	'<div class="bitly">
						<label for="strictlyautotags">'.__('Auto Linked With Strictly AutoTags', 'strictlytweetbot').'</label>
						<input type="checkbox" name="strictlyautotags" id="strictlyautotags" disabled checked /><span class="notes">'.__('If the Strictly AutoTags plugin is active and running it automatically links with the TweetBot plugin so that tags are always added before any tweets are sent. This is done by hooking into the AutoTags finished_doing_tagging action.', 'strictlytweetbot').'</span>
					</div>';
		}

		echo	'<h4>'.__('Bit.ly URL Shortening','strictlytweetbot').'</h4>
				<div class="bitly">
					<label for="tweetbot_bitlyAPIusername">'.__('Bit.ly Username', 'strictlytweetbot').'</label>
					<input type="text" name="tweetbot_bitlyAPIusername" id="tweetbot_bitlyAPIusername" value="' . esc_attr($this->bitlyAPIusername) . '" size="50" maxlength="50" />					
				</div>
				<div class="bitly">
					<label for="tweetbot_bitlyAPIkey">'.__('Bit.ly API Key', 'strictlytweetbot').'</label>
					<input type="text" name="tweetbot_bitlyAPIkey" id="tweetbot_bitlyAPIkey" value="' . esc_attr($this->bitlyAPIkey) . '" size="50" maxlength="50" />	
				</div>
				<div class="bitly">
					<label for="tweetbot_bitlyAPI">'.__('Bit.ly URL', 'strictlytweetbot').'</label>
					<select name="tweetbot_bitlyAPI" id="tweetbot_bitlyAPI" size="1">
						<option value="bit.ly" '	. (($this->bitlyAPI=="bit.ly" || empty($this->bitlyAPI)) ? "selected=\"selected\"" : "" ) . '>bit.ly</option>
						<option value="j.mp" '		. ($this->bitlyAPI=="j.mp"	? "selected=\"selected\"" : "" ) . '>j.mp</option>
					</select>
				</div></div></div>';

		
		if(is_array($this->accounts)){
			// output existing accounts first
			foreach($this->accounts as $account){

				$account_counter ++;				

				// for records already saved we lock the account name and twitter username to prevent edits
				// they can always delete the whole record. Also ensure there are keys for records that previously had duplicate names
				// and that for non saved items the whole account can be deleted without a POST and DB lookup.
				if(empty($this->saved_keys[$account])){
					$rr		= "";
					// create a random key value
					$keyval = uniqid($account);
					$sp		= ' id="hx_' . $account_counter . '"';
				}else{
					$rr		= " readonly=\"readonly\""; 
					$keyval = $this->saved_keys[$account];
					$sp		= "";
				}

				echo		'<div class="postbox" id="AccountWrapper' . $account_counter . '">
							<h3 class="hndle">'.__('TweetBot Options', 'strictlytweetbot').'</h3>					
							<div class="inside">
							<div class="TwitterAccount" id="Account' . $account_counter . '"><fieldset class="options">							
								<input type="hidden" name="tweetbot_key_' . $account_counter .'" id="tweetbot_key_' . $account_counter .'" value="' . esc_attr($keyval) . '" />
								<h4' . $sp .'>'.__('Auto Tweet Account','strictlytweetbot').'								
								<img class="delaccount" src="' .$this->pluginurl . 'cross.gif" alt="'.__('Cross','strictlytweetbot').'" title=\"'.__('Delete Account','strictlytweetbot').'\" onclick="TwitterAccount.deleteaccount(\''. $account_counter . '\',\'' . esc_attr($keyval) . '\');" />
								</h4>
								<div>
									<label for="tweetbot_account_' . $account_counter .'">'.__('Auto Tweet Account Name', 'strictlytweetbot').'</label><input type="text"' .$rr .' name="tweetbot_account_' . $account_counter .'" id="tweetbot_account_' . $account_counter .'" value="' . esc_attr($this->accounts[$account]) . '" size="50" maxlength="50" />
									<span class="notes">'.__('A unique descriptive name for this automatic tweet.', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_account_name_' . $account_counter .'">'.__('Twitter Account Username', 'strictlytweetbot').'</label><input type="text" ' .$rr .' name="tweetbot_account_name_' . $account_counter .'" id="tweetbot_account_name_' . $account_counter .'" value="' . esc_attr($this->account_names[$account]) . '" size="50" maxlength="50" />	
									<span class="notes">'.__('The Twitter account username / screename that you will be posting to.', 'strictlytweetbot').'</span>
								</div>';


				// handle non verified accounts
				if($this->IsVerified($account)){

						echo	'<input type="hidden" name="tweetbot_access_token_' . $account_counter .'" id="tweetbot_access_token_' . $account_counter .'" value="' . esc_attr($this->access_tokens[$account])  .'" />
						<input type="hidden" name="tweetbot_access_token_secret_' . $account_counter .'" id="tweetbot_access_token_secret_' . $account_counter .'" value="' . esc_attr($this->access_token_secrets[$account])  .'" />';
				}else{
						
						// create a request key/secret for any accounts that need to be verified

						$objOAuth			= new strictlyTwitterOAuth(APP_CONSUMER_KEY, APP_CONSUMER_SECRET);

						$request			= $objOAuth->getRequestToken();

						$requestToken		= $request['oauth_token'];
						$requestTokenSecret = $request['oauth_token_secret'];

						// get the URL users will visit to verify accounts
						$requestLink		= $objOAuth->getAuthorizeURL($request,false);
						

						echo	'<input type="hidden" name="tweetbot_request_token_' . $account_counter .'" id="tweetbot_request_token_' . $account_counter .'" value="' . esc_attr($requestToken) .'" />
								<input type="hidden" name="tweetbot_request_token_secret_' . $account_counter .'" id="tweetbot_request_token_secret_' . $account_counter .'" value="' . esc_attr($requestTokenSecret) .'" />';

						
		
						echo	'<div>
									<p class="VerifyMsg">' . __("This account requires verification by Twitter before posting can be enabled","strictlytweetbot") . '</p>
									<p><a target="_blank" href="' . $requestLink . '">' . __("Click here to authenticate","strictlytweetbot") . '</a></p>
									<span class="notes">'.__('Twitter will provide you with a PIN code which you must enter below to complete authentication. If Twitter complains about the link being too old you should refresh this page to obtain a new request token and then try again.', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_pin_' . $account_counter .'">'.__('PIN Code', 'strictlytweetbot').'</label><input type="text" name="tweetbot_pin_' . $account_counter .'" id="tweetbot_pin_' . $account_counter .'" value="" size="10" maxlength="10" />	
									<span class="notes">'.__('Twitter will provide you with a PIN code which you must enter to complete authentication.', 'strictlytweetbot').'</span>
								</div>';
				}

				echo			'<div>
									<label for="tweetbot_defaulttags_' . $account_counter .'">'.__('Default Hash Tags', 'strictlytweetbot').'</label><input type="text" name="tweetbot_defaulttags_' . $account_counter .'" id="tweetbot_defaulttags_' . $account_counter .'" value="' . esc_attr($this->defaulttags[$account]) . '" size="50" maxlength="140" />	
									<span class="notes">'.__('Tags to use if no categories or post tags are available e.g #Sales #WebDev.', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_contentanalysis_' . $account_counter .'">'.__('Content Analysis', 'strictlytweetbot').'</label>
									<input type="text" name="tweetbot_contentanalysis_' . $account_counter .'" id="tweetbot_contentanalysis_' . $account_counter .'" value="' . esc_attr($this->contentanalysis[$account]) . '" size="50" maxlength="150" /><br /><label></label>
									<input type="radio" name="tweetbot_contentanalysistype_' . $account_counter .'" id="tweetbot_contentanalysistype_all_' . $account_counter .'" value="ALL" ' .(($this->contentanalysistype[$account] == "ALL")?'checked="checked" ' : ''  ).' />
									' . __('All Words','strictlytweetbot') . '<input type="radio" name="tweetbot_contentanalysistype_' . $account_counter .'" id="tweetbot_contentanalysistype_any_' . $account_counter .'" value="ANY" ' .(($this->contentanalysistype[$account] == "ANY")?'checked="checked" ' : ''  ).' />' . __('Any Word','strictlytweetbot') . '<input type="radio" name="tweetbot_contentanalysistype_' . $account_counter .'" id="tweetbot_contentanalysistype_none_' . $account_counter .'" value="NONE" ' .(($this->contentanalysistype[$account] == "NONE")?'checked="checked" ' : ''  ).' />' . __('No Words','strictlytweetbot') . '<input type="radio" name="tweetbot_contentanalysistype_' . $account_counter .'" id="tweetbot_contentanalysistype_always_' . $account_counter .'" value="ALWAYS" ' .(($this->contentanalysistype[$account] == "ALWAYS")?'checked="checked" ' : ''  ).' />' . __('Always Post','strictlytweetbot') .'<span class="notes">'.__('Choose when to post the tweet. Picking <b>Always</b> will always tweet even with words in the input. Picking <b>All</b> means that all the words have to be in the article for the tweet to go out whilst <b>Any</b> means only one set of words has to exist to tweet. Select <b>None</b> if you don\'t want to tweet if certain words are in the article. Please separate words with a comma e.g Sales Manager, Managing.', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_ignoreterms_' . $account_counter .'">'.__('Ignore List', 'strictlytweetbot').'</label><textarea name="tweetbot_ignoreterms_' . $account_counter .'" id="tweetbot_ignoreterms_' . $account_counter .'" style="width:100%;height:75px;" />' . esc_attr($this->ignoreterms[$account]) . '</textarea><span class="notes">'.__('Words to ignore when using categories or post tags as hashtags. Separate each word with a comma e.g President Obama, New York City Library, Paul Smith', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_format_' . $account_counter .'">'.__('Tweet Format', 'strictlytweetbot').'</label>
									<input type="text" name="tweetbot_format_' . $account_counter .'" id="tweetbot_format_' . $account_counter .'" value="' . esc_attr($this->formats[$account]) . '" size="50" maxlength="140" />		
									<span class="notes">'.__('The format that the tweet will take e.g %title% %url% %hashtags%.', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_extra_querystring_' . $account_counter .'">'.__('Tracking Code', 'strictlytweetbot').'</label><input type="text" name="tweetbot_extra_querystring_' . $account_counter .'" id="tweetbot_extra_querystring_' . $account_counter .'" value="' . esc_attr($this->extra_querystring[$account]) . '" size="50" maxlength=\"500\" />
									<span class="notes">'.__('Add querystring parameters to the post url such as Google tracking codes before shortening e.g ?__utma=1.1695673470', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_tweetshrink_' . $account_counter .'">'.__('Tweet Shrink Title', 'strictlytweetbot').'</label><input type="checkbox" name="tweetbot_tweetshrink_' . $account_counter .'" id="tweetbot_tweetshrink_' . $account_counter .'" value="true" ' .(($this->tweetshrink[$account] )?'checked="checked" ' : ''  ).' />									
									<span class="notes">'.__('Use the Tweet Shrink API to shorten the title before posting', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_textshrink_' . $account_counter .'">'.__('Text Shrink Title', 'strictlytweetbot').'</label><input type="checkbox" name="tweetbot_textshrink_' . $account_counter .'" id="tweetbot_textshrink_' . $account_counter .'" value="true" ' .(($this->textshrink[$account] )?'checked="checked" ' : ''  ).' />									
									<span class="notes">'.__('Shorten the title before posting by using converting it to text speak e.g "See you tonight mate" would become "c u 2nite m8"', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_active_' . $account_counter .'">'.__('Active', 'strictlytweetbot').'</label>
									<input type="checkbox" name="tweetbot_active_' . $account_counter .'" id="tweetbot_active_' . $account_counter .'" value="true" ' .(($this->active[$account] )?'checked="checked" ' : ''  ).' />
									<span class="notes">'.__('Enable or disable this account from tweeting.', 'strictlytweetbot').'</span>
								</div>
								<div>
									<label for="tweetbot_tagtype_' . $account_counter .'">'.__('Hash Tag Source', 'strictlytweetbot').'</label>
									<input type="radio" name="tweetbot_tagtype_' . $account_counter .'" id="tweetbot_tagtype_tag" value="tag"
									' .(($this->tagtypes[$account] == "tag")?'checked="checked" ' : ''  ).' />'.__('Post Tags','strictlytweetbot').'
									<input type="radio" name="tweetbot_tagtype_' . $account_counter .'" id="tweetbot_tagtype_category" value="category"
									' .(($this->tagtypes[$account] == "category")?'checked="checked" ' : ''  ).' />' .__('Categories','strictlytweetbot'). '<input type="radio" name="tweetbot_tagtype_' .$account_counter . '" id="tweetbot_tagtype_default" value="default" ' .(($this->tagtypes[$account] == "default")?'checked="checked" ' : ''  ).' />'.__('Default','strictlytweetbot').'
									<span class="notes">'.__('The source of any Hash Tag values.', 'strictlytweetbot').'</span>					
								</div>
								</fieldset>
							</div>
							</div>
					</div>';
		
			}
		}

		// add another account
		echo	'</div>
					<input type="hidden" name="account_counter" id="account_counter" value="' . $account_counter . '" />
					<input type="hidden" name="account_delete_counter" id="account_delete_counter" value="" />
					<p><input type="button" value="'.__('Add Twitter Account','strictlytweetbot').'" onclick="TwitterAccount.addaccount();" id="cmdAdd" name="cmdAdd" />
						<input type="submit" value="'.__('Save Settings','strictlytweetbot').'" id="cmdSubmit" name="cmdSubmit" />
						<input type="submit" value="'.__('Delete All Accounts','strictlytweetbot').'" id="cmdDelete" name="cmdDelete" onclick="return confirm(\'' . __('Are you sure you want to remove all accounts from the system?','strictlytweetbot') . '\');" />
						<input type="submit" value="'.__('Test Configuration','strictlytweetbot').'" id="cmdTestConfig" name="cmdTestConfig" />
					</p>';
					
		echo			'
				</form>';

		
		echo	'<div class="postbox">
					<h3 class="hndle">'.__('Donate to Stictly Software', 'strictlytweetbot').'</h3>					
					<div class="inside">
					<p>'.__('Your help ensures that my work continues to be free and <strong>any amount</strong> is appreciated. Please consider setting up a subscription payment of a couple of pounds each month if you can afford it. If everyone donated me just a few pounds I could concentrate on making great WordPress plugins full time!', 'StrictlySystemCheck').'</p>';
		
		echo	'<div style="text-align:center;"><br />
				<form action="https://www.paypal.com/cgi-bin/webscr" method="post"><br />
				<input type="hidden" name="cmd" value="_s-xclick"><br />
				<input type="hidden" name="hosted_button_id" value="6427652"><br />
				<input type="image" src="https://www.paypal.com/en_GB/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online.">
				<br /></form></div></div></div>';


		echo	'<div class="postbox">
					<h3 class="hndle">'.__('Stictly Software Recommendations', 'strictlytweetbot').'</h3>					
					<div class="inside">
					<div class="recommendations"><p>'.__('If you enjoy using this WordPress plugin you might be interested in some other websites, tools and plugins I have developed.', 'StrictlySystemCheck').'</p>
					<ul>															
						<li><a href="http://wordpress.org/extend/plugins/strictly-autotags/">'.__('Strictly Auto Tags','StrictlySystemCheck').'</a>
							<p>'.__('Strictly Auto Tags is a popular Wordpress plugin that automatically adds the most relevant tags to published posts. <strong>This is great for automatic blogs that import articles from feeds</strong> as it ensures articles are tagged correctly. Used with this plugin you can get tags and #hashtags for your tweets which will be automatically sent when a post is published and all tagging is complete. There are two versions a free one and a paid for version with many more features including hooks to notify other plugins such as Strictly TweetBOT once the tagging is done so that <strong>new tags can be used as #HashTags</strong>, the ability to add certain words as tags if other words are found instead, the choice to mark words that appear in titles, headers and links higher than other content, the ability to set important site keywords as &quot;Top Tags&quot; that are ranked higher than any other content, <strong>SEO features to deeplink and bold content</strong> plus the ability to reformat the content of the article and convert textual links to real clickable ones.','StrictlySystemCheck').'</p>
						</li>
						<li><a href="http://wordpress.org/extend/plugins/strictly-system-check/">'.__('Strictly System Check','strictlytweetbot').'</a>
							<p>'.__('Strictly System Check is a WordPress plugin that allows you to automatically check your sites status at scheduled intervals to ensure it\'s running smoothly. It will run various system checks on the website, database and server and send you an email if anything doesn\'t meet your requirements. It tells you how long your server has been running, whether your system is overloaded, whether the database needs REPAIRING or OPTIMIZING plus lots more information.','strictlytweetbot').'</p>
						</li>						
						<li><a href="http://www.ukhorseracingttipster.com">'.__('UK Horse Racing Tipster','StrictlySystemCheck').'</a>
							<p>'.__('A top tipping site for horse racing fans with racing news, free tips to your email inbox and a premium service that offers <strong>high return on investment and profitable horse racing tips each day.</strong> From Lay, Place to Win tips <strong>we have over 63 NAP tipsters providing WIN TIPS each day</strong> and two lots of members only tips from over 521 systems including some that return <strong>over &pound;3,500 profit a month! </strong>','StrictlySystemCheck').'</p>
						</li>
						<li><a href="http://www.fromthestables.com">'.__('From The Stables','StrictlySystemCheck').'</a>
							<p>'.__('If you like horse racing or betting and want that extra edge when using Betfair then this site is for you. It\'s a members only site that gives you inside information straight from the UK\'s top racing trainers every day. <strong>We reguarly post up to 5 winners a day</strong> and our members have won thousands since we started in 2010.','StrictlySystemCheck').'</p>
						</li>
						<li><a href="http://www.darkpolitricks.com">'.__('Dark Politricks','StrictlySystemCheck').'</a>
							<p>'.__('Tired of being fed the same old talking points from your news provider? Want opinions from outside the box? Want to know the important news that the mainstream media won\'t report? Then this site is for you. Alternative news, comment and analysis all in one place. Great essays, rants and opinion from the best of the altnerative media','StrictlySystemCheck').'</p>
						</li>						
					</ul>
				</div></div></div>'; 
		
	}
}

