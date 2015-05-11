<?php


class UNL_MediaHub_Media extends UNL_MediaHub_Models_BaseMedia implements UNL_MediaHub_MediaInterface
{
    /**
     * Get a piece of media by PK.
     *
     * @param int $id ID of the media.
     *
     * @return UNL_MediaHub_Media
     */
    static function getById($id)
    {
        return Doctrine::getTable(__CLASS__)->find($id);
    }
    
    /**
     * Get a piece of media by URL
     *
     * @param string $url URL to the video/audio file
     * 
     * @return UNL_MediaHub_Media
     */
    public static function getByURL($url)
    {
        $media = Doctrine::getTable(__CLASS__)->findOneByURL($url);
        if ($media) {
            return $media;
        }
        return false;
    }
    
    public static function formatNumber($number, $precision = 0)
    {
        $suffix = '';
        $exp = floor(log($number, 10));
        
        if ($exp >= 6) {
            $exp = 6;
            $suffix = ' M';
        } elseif ($exp >= 4) {
            $exp = 3;
            $suffix = ' k';
        } else {
            $exp = 0;
        }
        
        $value = round($number / pow(10, $exp), $precision) . $suffix;
        return $value;
    }
    
    /**
     * called before an item is inserted to the database
     * 
     * @param $event
     * 
     * @return void
     */
    public function preInsert($event)
    {
        $this->setContentType();
    }

    /**
     * called before an item is updated in the database
     * 
     * @param $event
     * 
     * @return void
     */
    public function preUpdate($event)
    {
        $this->setContentType();
    }
    
    /**
     * called after an item is inserted into the database
     * 
     * @param $event
     * 
     * @return void
     */
    public function postInsert($event)
    {
        if ($this->isVideo()) {
            $this->setMRSSThumbnail();
        }
        $this->setMRSSContent();
    }

    public function postSave($event)
    {
//        var_dump('postsave');
//        $this->setMRSSThumbnail();
//        $this->setMRSSContent();
    }
    
    /**
     * Sets the thumbnail media rss element.
     * 
     * @return true
     */
    function setMRSSThumbnail()
    {
        if ($element = UNL_MediaHub_Feed_Media_NamespacedElements_media::mediaHasElement($this->id, 'thumbnail')) {
            // all ok
        } else {
            $element = new UNL_MediaHub_Feed_Media_NamespacedElements_media();
            $element->media_id = $this->id;
            $element->element = 'thumbnail';
        }
        $attributes = array('url' => $this->getThumbnailURL(),
                            //width="75" height="50" time="12:05:01.123"
                            );
        $element->attributes = $attributes;
        $element->save();
        return true;
    }

    function getVideoDimensions()
    {
        if ($element = UNL_MediaHub_Feed_Media_NamespacedElements_media::mediaHasElement($this->id, 'content')) {
            return array(0=>$element->attributes['width'], 1=>$element->attributes['height']);
        }
        return getimagesize($this->getThumbnailURL());
    }

    /**
     * Set the Media RSS, mrss content namespaced element
     * 
     * @return true
     */
    function setMRSSContent()
    {
        if ($element = UNL_MediaHub_Feed_Media_NamespacedElements_media::mediaHasElement($this->id, 'content')) {
            // all good
        } else {
            $element = new UNL_MediaHub_Feed_Media_NamespacedElements_media();
            $element->media_id = $this->id;
            $element->element = 'content';
        }
        $attributes = array('url'      => $this->url,
                            'fileSize' => $this->length,
                            'type'     => $this->type,
                            'lang'     => 'en');
        if ($this->isVideo()) {
            list($width, $height) = $this->getVideoDimensions();
            $attributes['width']  = $width;
            $attributes['height'] = $height;
        }

        if (isset($element->attributes) && is_array($element->attributes)) {
            $attributes = array_merge($element->attributes, $attributes);
        }
        $element->attributes = $attributes;
        $element->save();
        return true;
    }
    
    /**
     * Check if this media is a video file.
     * 
     * @param string URL or content-type
     * 
     * @return bool
     */
    public function isVideo()
    {
        return UNL_MediaHub::isVideo($this->type);
    }
    
    /**
     * Sets the content type for the media being added.
     * 
     * @return bool
     */
    function setContentType()
    {
        if (!filter_var($this->url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $context = stream_context_create(array('http'=>array(
                'method'     => 'GET',
                'user_agent' => 'UNL MediaHub/mediahub.unl.edu'
                )));

        $result = @file_get_contents($this->url, null, $context, -1, 8);
        if (false === $result) {
            // Could not retrieve the info about this piece of media
            return false;
        }

        if (false !== $http_response_header && count($http_response_header)) {
            foreach($http_response_header as $header) {
                if (strpos($header, 'Content-Type: ') !== false) {
                    $this->type = substr($header, 14);
                }
                if (strpos($header, 'Content-Length: ') !== false) {
                    $this->length = substr($header, 16);
                }
            }
        }
        return true;
    }

    function getFeeds()
    {
        return new UNL_MediaHub_FeedList(array('limit'=>null, 'filter'=>new UNL_MediaHub_FeedList_Filter_WithMediaId($this->id)));
    }

    function delete(Doctrine_Connection $conn = null)
    {
        $feeds = $this->getFeeds();
        $feeds->run();

        if (count($feeds->items)) {
            foreach ($feeds->items as $feed) {
                $feed->removeMedia($this);
            }
        }

        try {
            foreach (array('UNL_MediaHub_Feed_Media_NamespacedElements_itunesu',
                           'UNL_MediaHub_Feed_Media_NamespacedElements_itunes',
                           'UNL_MediaHub_Feed_Media_NamespacedElements_media',
                           'UNL_MediaHub_Feed_Media_NamespacedElements_boxee') as $ns_class) {
                foreach ($this->$ns_class as $namespaced_element) {
                    $namespaced_element->delete();
                }
            }
        } catch (Exception $e) {
            // Error, just skip this for now.
        }
        
        $local_file = $this->getLocalFileName();
        
        if (!parent::delete($conn)) {
            return false;
        }
        
        if ($local_file && !is_dir($local_file)) {
            //Delete the file, and make sure it isn't a directory for some unknown reason.
            unlink($local_file);
        }
        
        return true;
    }

    /**
     * Get the local file name for this media.  It will be an absolute path if found.
     * 
     * @return bool|string
     */
    public function getLocalFileName()
    {
        $uploads_url = UNL_MediaHub_Controller::getURL() . 'uploads/';
        if (strpos($this->url, $uploads_url) !== 0) {
            return false;
        }
        
        $file = UNL_MediaHub_Manager::getUploadDirectory() . '/' . str_replace($uploads_url, '', $this->url);
        
        if (!file_exists($file)) {
            return false;
        }
        
        return $file;
    }

    /**
     * Get the tags associated with this media file
     * 
     * @return array()
     */
    function getTags()
    {
        $tags = array();
        $class = 'UNL_MediaHub_Feed_Media_NamespacedElements_itunes';
        if ($element = call_user_func($class .'::mediaHasElement', $this->id, 'keywords')) {
            $tags = explode(',', $element->value);
            array_walk($tags, 'trim');
        }
        return $tags;
    }

    /**
     * Add a new tag for this media.
     * 
     * @param string $newTag Tag or comma separated list of tags to add
     */
    function addTag($newTag)
    {
        $tags = $this->getTags();
        $class = 'UNL_MediaHub_Feed_Media_NamespacedElements_itunes';
        if (!in_array(strtolower($newTag), $tags)) {
            array_push($tags, strtolower($newTag));
            sort($tags);
            $tagStr = implode(",", $tags);
            return call_user_func($class .'::mediaSetElement', $this->id, 'keywords', $tagStr);
        }
        return false;
    }

    /**
     * Get the URL to the thumbnail for this image
     *
     * @return string
     */
    function getThumbnailURL()
    {
        if (!empty($this->poster)) {
            return $this->poster;
        }

        if (!$this->isVideo()) {
            return UNL_MediaHub_Controller::getURL().'templates/html/css/images/waveform.png';
        }
        
        return UNL_MediaHub_Controller::$thumbnail_generator.urlencode($this->url);
    }

    function getStreamingURL()
    {
        if (false === strpos($this->url, 'http://real.unl.edu/podcast/')) {
            return false;
        }
        return str_replace('http://real.unl.edu/podcast/', 'rtmp://real.unl.edu/content/podcast/', $this->url);
    }

    public function getVideoTextTrackURL($format = 'srt')
    {
        return UNL_MediaHub_Controller::$url.'media/'.$this->id.'/'.$format;
    }
    
    static function getPossiblePrivacyValues()
    {
        $table = Doctrine::getTable('UNL_MediaHub_Media');
        $column = $table->getColumnDefinition('privacy');
        return $column['values'];
    }

    /**
     * @return bool
     */
    public function canView()
    {
        //If its not private, anyone can view it.
        if ($this->privacy != 'PRIVATE') {
            return true;
        }

        $user = UNL_MediaHub_AuthService::getInstance()->getUser();
        //At this point a user needs to be logged in.
        if (!$user) {
            return false;
        }
        
        //Get a list of feeds for this user that contain this media.
        $feeds = new UNL_MediaHub_FeedList(array(
            'limit'=>null,
            'filter'=>new UNL_MediaHub_FeedList_Filter_ByUserWithMediaId($user, $this->id)
        ));

        $feeds->run();

        //Can view only if they are a member of the at least one of the feeds (specific permissions don't matter).
        if (empty($feeds->items)) {
            return false;
        }
        
        return true;
    }

    /**
     * @param UNL_MediaHub_User $user
     * @return bool
     */
    public function userCanEdit(UNL_MediaHub_User $user)
    {
        return $this->userHasPermission($user, UNL_MediaHub_Permission::USER_CAN_UPDATE);
    }

    /**
     * @param UNL_MediaHub_User $user
     * @return bool
     */
    public function userCanDelete(UNL_MediaHub_User $user)
    {
        return $this->userHasPermission($user, UNL_MediaHub_Permission::USER_CAN_DELETE);
    }

    /**
     * @param UNL_MediaHub_User $user
     * @param $permission_id - the id of the permission to check against
     * @return bool
     */
    public function userHasPermission(UNL_MediaHub_User $user, $permission_id)
    {
        //Is the user logged in?
        if (!$user) {
            return false;
        }
        
        if (!$permission = UNL_MediaHub_Permission::getByID($permission_id)) {
            return false;
        }

        $feeds = new UNL_MediaHub_FeedList(array(
            'limit'=>null,
            'filter'=>new UNL_MediaHub_FeedList_Filter_ByUserWithMediaId($user, $this->id)
        ));

        $feeds->run();

        foreach($feeds->items as $feed) {
            if ($feed->userHasPermission($user, $permission)) {
                return true;
            }
        }

        return false;
    }
    
    public function getTextTracks()
    {
        $api = new UNL_MediaHub_AmaraAPI();
        return $api->getMediaHubTextTracks($this->id, $this->url);
    }
}

