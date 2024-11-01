<?php

/**
 * Plugin Name: Strictly TweetBot
 * Version: 1.1.5
 * Plugin URI: http://www.strictly-software.com/plugins/strictly-tweetbot/
 * Description: Allows you to post messages to multiple twitter accounts when new articles are added. Options to reformat the messages and use post tags and categories as hash tags automatically.
 * Author: Rob Reid
 * Author URI: http://www.strictly-software.com 
 * =======================================================================
 */


/**
 *
 * GPL Licence:
 * ==============================================================================
 * Copyright 2010 Strictly Software
 * 
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * 
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 */

require_once(dirname(__FILE__) . "/strictly-tweetbot.class.php");
require_once(dirname(__FILE__) . "/strictly-twitter.class.php");



class StrictlyTweetControl{

	private static $StrictlyTweetBot;


	/**
	 * Init is called on every page not just when the plugin is activated and creates an instance of my strictly autotag class if it doesn't already exist
	 *
	 */
	public static function Init(){
		
		if(!isset(StrictlyTweetControl::$StrictlyTweetBot)){
			// create class and all the good stuff that comes with it
			StrictlyTweetControl::$StrictlyTweetBot = new StrictlyTweetBot(); 
		}

	}
	/**
	 * Called when plugin is deactivated and removes all the settings related to the plugin
	 *
	 */
	public static function Deactivate(){

		if(get_option('strictlytweetbot_uninstall')){

			delete_option("strictlytweetbot_messages");
			delete_option("strictlytweetbot_options");
			delete_option("strictlytweetbot_uninstall");

		}

	}

	/**
	 * Called when plugin is deactivated and removes all the settings related to the plugin
	 *
	 */
	public static function Activate(){

		// log the install date if we haven't already got one
		if(!get_option('strictlytweetbot_install_date')){
			update_option('strictlytweetbot_install_date', current_time('mysql'));
		}

	}


	/**
	 * Returns the path to the blog directory - taken from Arne Bracholds Sitemap plugin
	 *		
	 * @return string The full path to the blog directory
	*/
	public static function GetHomePath() {
		
		$res="";
		//Check if we are in the admin area -> get_home_path() is avaiable
		if(function_exists("get_home_path")) {
			$res = get_home_path();
		} else {
			//get_home_path() is not available, but we can't include the admin
			//libraries because many plugins check for the "check_admin_referer"
			//function to detect if you are on an admin page. So we have to copy
			//the get_home_path function in our own...
			$home = get_option( 'home' );
			if ( $home != '' && $home != get_option( 'siteurl' ) ) {
				$home_path	= parse_url( $home );
				$home_path	= $home_path['path'];
				$root		= str_replace( $_SERVER["PHP_SELF"], '', $_SERVER["SCRIPT_FILENAME"] );
				$home_path	= trailingslashit( $root.$home_path );
			} else {
				$home_path	= ABSPATH;
			}

			$res = $home_path;
		}
		return $res;
	}



}


// register my activate hook to setup the plugin
register_activation_hook(__FILE__, 'StrictlyTweetControl::Activate');

// register my deactivate hook to ensure when the plugin is deactivated everything is cleaned up
register_deactivation_hook(__FILE__, 'StrictlyTweetControl::Deactivate');

add_action('init', 'StrictlyTweetControl::Init');

//$StrictlyTweetBot = new StrictlyTweetBot();