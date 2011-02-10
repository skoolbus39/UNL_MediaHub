<?php
class UNL_MediaYak_Subscription extends UNL_MediaYak_Models_BaseSubscription
{
    /**
     * Processes this subscription and adds media not currently
     * added to the feed this subscription is for.
     *
     * @return int number of media added to the feed
     */
    public function process(UNL_MediaYak_Feed $feed)
    {
        $added      = 0;
        $filter     = $this->getFilter();
        $media_list = $this->getMediaList($filter);

        foreach ($media_list as $media) {
            if (!$feed->hasMedia($media)) {
                if ($feed->addMedia($media)) {
                    $added++;
                }
            }
        }

        return $added;
    }

    protected function getFilter()
    {
        $class  = $this->filter_class;
        return new $class($this->filter_option);
    }

    protected function getMediaList(UNL_MediaYak_MediaList_Filter $filter)
    {
        return new UNL_MediaYak_MediaList(array('filter'=>$filter));
    }
}