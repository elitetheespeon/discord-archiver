<?php
class Archiver{
    //Define F3 instance
    var $f3;
    
    //Define RestCord instance
    var $discord;

    //Define Monolog instance
    var $logger;

    //Define Guzzle client instance
    var $client;
    
    //Define last message number
    var $last_msg;

    //Define messages
    var $messages;
    
    //Define users
    var $users;    

    //Set up handles
    function __construct($f3){
        $this->f3 = $f3;
        $this->logger = new ColorLog("bridge.log");
        $this->discord = new RestCord\DiscordClient(['token' => $f3->get('discord_token'), 'logger' => $this->logger->log_err, 'throwOnRatelimit' => false]);
        $this->client = new GuzzleHttp\Client();
        $this->last_msg = false;
        $this->messages = [];
        $this->users = [];
    }
    
    //Get all messages
    function get_all_messages(){
        //Check we have at least one channel defined
        if(count($this->f3->get('channels')) == 0){
            //No channels defined
            $this->logger->log_err('No channels have been defined, quitting!');
            return;
        }
        
        //Loop through channels
        foreach($this->f3->get('channels') as $channel){
            //Get channel info
            $channel_info = $this->discord->channel->getChannel(['channel.id' => $channel]);
            
            //Save channel info
            $this->f3->set('channel_info', $channel_info);

            //Get guild info
            $guild_info = $this->discord->guild->getGuild(['guild.id' => $channel_info->guild_id]);

            //Get guild channels
            $guild_channels = $this->discord->guild->getGuildChannels(['guild.id' => $channel_info->guild_id]);
            
            //Set channels defaults
            $channels = [];
            
            //Sort channels array
            foreach($guild_channels as $guild_channel){
                $channels[$guild_channel->id] = $guild_channel->name;
            }
            
            //Save guild channels info
            $this->f3->set('channels_info', $channels);
            
            //Set role defaults
            $roles = [];
            
            //Sort role array
            foreach($guild_info->roles as $role){
                $roles[$role->id] = $role;
            }
            
            //Save role info
            $this->f3->set('role_info', $roles);

            //Get channel messages
            $this->get_messages($channel);
        }
    }

    //Get block of messages
    function get_messages($channel, $last_msg = false){
        //Set default options
        $options = ['channel.id' => $channel, 'limit' => 100];
        
        //Check if last message was sent
        if($last_msg['id']){
            //Merge before id into options
            $options = array_merge($options, ['before' => (int)$last_msg['id']]);
        }
        
        dump($options);
        
        //Get messages
        try{
            //Get channel messages 100 at a time
            $messages = $this->discord->channel->getChannelMessages($options);
        }catch(Exception $e){
            //Error getting messages
            $this->logger->log_err('Error getting messages: '.$e->getMessage());
            $messages = false;
        }
        
        //Check messages returned
        if(count($messages) == 0){
            //No messages returned
            $this->logger->log_err('No messages were returned');
            return;
        }
        
        //Loop through messages returned
        foreach($messages as $message){
            //Compare current message month with last
            if(date('Y-m',date('U', strtotime($message['timestamp']))) != date('Y-m',date('U', strtotime($last_msg['timestamp']))) && $last_msg['timestamp']){
                //Last message month is different, kick off archive script to close out month
                $this->archive_month();
            }

            //Get user info for role color
            $role_color = $this->get_user_info($this->f3->get('channel_info')->guild_id, $message['author']['id']);
            
            //Check if role color was returned
            if($role_color){
                //Role color returned, set color
                $message['role_color'] = $role_color['highest_role'];
            }else{
                //Invalid role color, set default
                $message['role_color'] = false;
            }
            
            //Check for mentioned users
            if(count($message['mentions']) > 0){
                //Replace mentioned users in content
                $message['content'] = $this->replace_mentioned_users($message['content'], $message['mentions']);
            }
            
            //Check for mentioned roles
            if(count($message['mention_roles']) > 0){
                //Replace mentioned roles in content
                $message['content'] = $this->replace_mentioned_roles($message['content'], $message['mention_roles']);
            }
            
            //Check for mentioned channels
            if(preg_match_all("/<#(\d+?)>/", $message['content'], $channels)){
                //Replace mentioned channels in content
                $message['content'] = $this->replace_mentioned_channels($message['content'], $channels);
            }
            
            //Check for animated emojis
            if(preg_match_all("/<a:(\w+?):(\d+?)>/", $message['content'], $emojis)){
                //Replace animated emojis in content
                $message['content'] = $this->replace_animated_emoji($message['content'], $emojis);
            }

            //Check for emojis
            if(preg_match_all("/<:(\w+?):(\d+?)>/", $message['content'], $emojis)){
                //Replace emojis in content
                $message['content'] = $this->replace_emoji($message['content'], $emojis);
            }
            
            //Turn any URLs in content into clickable URLs that are shortened down
            $message['content'] = $this->short_url($message['content']);
            
            //Render any markdown into HTML equivalents
            $message['content'] = \Markdown::instance()->convert($message['content']);
            $message['content'] = str_replace('<p>', '', $message['content']);
            $message['content'] = str_replace('</p>', '', $message['content']);
            
            //Render message into HTML
            $this->render_message($message);
            
            //Check for attachments
            if(count($message['attachments']) > 0){
                //Message has attachments, loop through them
                foreach ($message['attachments'] as $attachment){
                    //Download attachment
                    $this->download_attachment($attachment);
                }
            }
            
            //Save message id
            $last_msg = $message;
        }

        //Wait 5 seconds
        sleep(5);
        
        //Get next set of messages
        $this->get_messages($channel, $last_msg);
    }

    //Get user info and store
    function get_user_info($guild_id, $user_id){
        //Check if user info is cached
        if($this->users[$user_id]){
            //User already cached, return cached user info
            return $this->users[$user_id];
        }else{
            try{
                //User is not cached, get user info
                $user_info = $this->discord->guild->getGuildMember(['guild.id' => (int)$guild_id, 'user.id' => (int)$user_id]);
            }catch(Exception $e){
                //Could not get user info
                $user_info = false;
            }
            
            //Set default for role info
            $role_info = [];
            
            //Check for valid user info
            if($user_info){
                //Loop through user roles
                foreach($user_info->roles as $role){
                    //Get role position
                    $role_pos = $this->f3->get('role_info')[$role]->position;
                    
                    //Store role position
                    $role_info[$role] = $role_pos;
                }
                
                //Sort roles by highest position
                arsort($role_info);
                
                //Get role with highest position
                $highest_role = key($role_info);
                
                //Cache user info
                $this->users[$user_id]['highest_role'] = $highest_role;
                $this->users[$user_id]['username'] = $user_info->username;
                $this->users[$user_id]['desc'] = $user_info->discriminator;
                
                //Wait 1 second
                sleep(1);
                
                //Return highest position
                return $this->users[$user_id];
            }else{
                //Invalid user info
                return false;
            }
        }
    }

    //Replace mentioned users with usernames in message content
    function replace_mentioned_users($content, $mentions){
        //Loop through mentions
        foreach($mentions as $mention){
            //Replace mentioned user id with username
            $content = str_replace("<@{$mention['id']}>", '<a href="">@'.$mention['username'].'</a>', $content);
            $content = str_replace("<@!{$mention['id']}>", '<a href="">@'.$mention['username'].'</a>', $content);
        }
        
        //Return back content with all replacements
        return $content;
    }

    //Replace mentioned roles with role names in message content
    function replace_mentioned_roles($content, $mentions){
        //Loop through mentions
        foreach($mentions as $mention){
            //Replace mentioned role id with role name
            $content = str_replace("<@&{$mention}>", '<a href="">@'.$this->f3->get('role_info')[$mention]->name.'</a>', $content);
        }
        
        //Return back content with all replacements
        return $content;
    }

    //Replace mentioned channels with channel names in message content
    function replace_mentioned_channels($content, $mentions){
        //Loop through mentions
        foreach($mentions[1] as $num => $mention){
            //Replace mentioned role id with role name
            $content = str_replace($mentions[0][$num], '<a href="">#'.$this->f3->get('channels_info')[$mentions[1][$num]].'</a>', $content);
        }
        
        //Return back content with all replacements
        return $content;
    }
    
    //Replace animated emoji with url in message content
    function replace_animated_emoji($content, $emojis){
        //Loop through emojis
        foreach($emojis[2] as $num => $emoji){
            //Replace emoji id with URL to emoji
            $content = str_replace($emojis[0][$num], "<img class='emoji' src='https://cdn.discordapp.com/emojis/{$emojis[2][$num]}.gif'>", $content);
        }
        
        //Return back content with all replacements
        return $content;
    }

    //Replace emoji with url in message content
    function replace_emoji($content, $emojis){
        //Loop through emojis
        foreach($emojis[2] as $num => $emoji){
            //Replace emoji id with URL to emoji
            $content = str_replace($emojis[0][$num], "<img class='emoji' src='https://cdn.discordapp.com/emojis/{$emojis[2][$num]}.png'>", $content);
        }
        
        //Return back content with all replacements
        return $content;
    }

    //Write out stored messages for month to HTML
    function archive_month(){
        //Render header HTML
        $html = Template::instance()->render('header.html');
        
        //Check message sort order
        if($this->f3->get('message_sort') == 'ascending'){
            //Reverse day order
            $this->messages[key($this->messages)] = array_reverse($this->messages[key($this->messages)]);
            
            //Loop through each day
            foreach($this->messages[key($this->messages)] as $day => $messages){
                //Reverse messages so they are ascending (default descending)
                $this->messages[key($this->messages)][$day] = array_reverse($this->messages[key($this->messages)][$day]);
            }
        }
        
        //Loop through messages stored (month)
        foreach($this->messages as $msgs_by_month){
            //Loop through messages stored (day)
            foreach($msgs_by_month as $msgs_by_day){
                //Get timestamp of first message of day
                $this->f3->set('timestamp', $msgs_by_day[0]['timestamp']);
                
                //Render day spacer as HTML
                $day_spacer = Template::instance()->render('day_divider.html');
                
                //Add day spacer to HTML
                $html = $html."\n".$day_spacer;
                
                //Loop through messages
                foreach($msgs_by_day as $message){
                    //Build messages HTML
                    $html = $html."\n".$message['html'];
                    
                    //Check if message month and year has been set
                    if(!$month || !$year){
                        //Set message month and year
                        $month = date('M',date('U', strtotime($message['timestamp'])));
                        $year = date('Y',date('U', strtotime($message['timestamp'])));
                    }
                }
            }
        }
        
        //Render footer HTML
        $footer = Template::instance()->render('footer.html');
        
        //Add footer HTML
        $html = $html."\n".$footer;
        
        //Save channel info for file name
        $channel_name = $this->f3->get('channel_info')->name;
        
        //Save HTML file to disk
        file_put_contents("archives/{$channel_name}-{$month}-{$year}.html", $html.PHP_EOL);

        //Render footer HTML
        $roles_css = Template::instance()->render('roles.css');

        //Save HTML file to disk
        file_put_contents("archives/{$channel_name}-roles.css", $roles_css.PHP_EOL);

        //Render footer HTML
        $style_css = Template::instance()->render('style.css');

        //Save HTML file to disk
        file_put_contents("archives/style.css", $style_css.PHP_EOL);
        
        //Dispose of stored messages
        $this->messages = [];
    }

    //Download an attachment and store on disk
    function download_attachment($attachment){
        //Create local file
        $download = fopen("archives/att/{$attachment['id']}.{$attachment['filename']}", 'w');

        //Download file from Discord
        $response = $this->client->get($attachment['url'], ['save_to' => $download]);
        
        if($response->getStatusCode() == 200){
            //Download completed successfully
            return true;
        }else{
            //Download failed
            return false;
        }
    }

    //Turn any URLs in content into clickable URLs that are shortened down
	public function short_url($text){
		$urlRegex = "((?:https?|ftp)\:\/\/)"; /// Scheme
		$urlRegex .= "([a-zA-Z0-9+!*(),;?&=\$_.-]+(\:[a-zA-Z0-9+!*(),;?&=\$_.-]+)?@)?"; /// User and Password
		$urlRegex .= "([a-zA-Z0-9.-]*)\.([a-zA-Z]{2,3})"; /// Domain or IP
		$urlRegex .= "(\:[0-9]{2,5})?"; /// Port
		$urlRegex .= "(\/([a-zA-Z0-9+\$_-]\.?)+)*\/?"; /// Path
		$urlRegex .= "(\?[a-zA-Z+&\$_.-][a-zA-Z0-9;:@&%=+\/\$_.-]*)?"; /// GET Query
		$urlRegex .= "(#[a-zA-Z_.-][a-zA-Z0-9+\$_.-]*)?"; /// Anchor
		
		$linkRegex = '/"(.+)"\:('. $urlRegex . ')/ms';
		
		$fullUrlRegex = "/^"; /// Start Regex (PHP is stupid)
		$fullUrlRegex .= "("; /// Catch whole url except garbage
		$fullUrlRegex .= $urlRegex;
		$fullUrlRegex .= ").*"; /// End of catching whole url
		$fullUrlRegex .= "$/"; /// End Regex
		$fullUrlRegex .= "m"; /// Allow multi line match (and ^ and & )
		$fullUrlRegex .= "s"; /// Don't stop when finding an \n.

		$links = array();
		$urls = array();
		 
		if(!$this->is_url_possible($text)){
            /// Do nothing, because the text is too small for urls.
        }else{
            $allTheWords = preg_split('/\s|(\<br ?\/\>)/', $text);

            foreach($allTheWords as $word){
                if($this->is_url_possible($word)){
                    $matches = array();
                    $ambigiousResultFullUrl = preg_match($fullUrlRegex, $word, $matches);
                    if($ambigiousResultFullUrl === TRUE || $ambigiousResultFullUrl === 1){
                        $embeddedLinks[] = $word;
                    }

                    $ambigiousResultLink = preg_match($linkRegex, $word, $matches);
                    if($ambigiousResultLink === TRUE || $ambigiousResultLink === 1){
                        $description = $matches[1];
                        $url = $matches[2];
                        $urls[$word] = '<a href="' . $url . '" rel="nofollow">' . $description . '</a>';
                    }
                }
            }

            
            //Shorten each found embedded url longer than 60 chars with ellipses.
            //Otherwise, show them completely.
			//Added a check to see if embeddedLinks actually contained data
			if ($embeddedLinks){
	            foreach( $embeddedLinks as $url ){
	                $linkLength = strlen( $url );
	
	                if($linkLength > 60){
	                    $urlFirstPart = substr( $url, 0, 25 );
	                    $urlSecondPart = substr( $url, -25, $linkLength );
	                    $displayUrl = '<a href="' . $url . '" rel="nofollow">' . $urlFirstPart . '...' . $urlSecondPart . '</a>';
	                }else{
	                    $displayUrl = '<a href="' . $url . '" rel="nofollow">' . $url . '</a>';
	                }
	                $urls[$url] = $displayUrl;
	            }
	

	            //Replace each embedded url with its displayUrl:
	            foreach($urls as $url => $displayUrl){
	                $text = str_replace($url, $displayUrl, $text);
	            }
			}
        }
        return $text;
	}
	
	//Check if word could possibly be a URL
    function is_url_possible($text){
        return 10 <= strlen($text);
    }

    //Render a message into HTML
    function render_message($message){
        //Format date for message array
        $year = date('Y',date('U', strtotime($message['timestamp'])));
        $month = date('m',date('U', strtotime($message['timestamp'])));
        $day = date('d',date('U', strtotime($message['timestamp'])));

        //Set message var for template
        $this->f3->set('m', $message);
        
        //Render message into HTML
        $this->messages[$month][$day][] = ['html' => Template::instance()->render('message.html'), 'timestamp' => $message['timestamp'], 'author' => $message['author']['id']];
    }
}