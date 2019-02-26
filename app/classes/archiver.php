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
            
            //Get role info
            $role_info = $this->discord->guild->getGuildRoles(['guild.id' => $channel_info->guild_id]);
            $roles = [];
            
            //Re-sort array
            foreach($role_info as $role){
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

            //Get role color
            $message['role_color'] = $this->get_user_info($this->f3->get('channel_info')->guild_id, $message['author']['id']);
            
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
                $this->users[$user_id] = $highest_role;
    
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

    //Write out stored messages for month to HTML
    function archive_month(){
        //Render header HTML
        $html = Template::instance()->render('header.html');
        
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