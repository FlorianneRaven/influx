<?php

class Category
{
    private $logger;
    private $db;

    private $id;
    private $name;
    private $parent;
    private $isopen;

    /*
     *
     * | categories | CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(225) NOT NULL,
  `parent` int(11) NOT NULL,
  `isopen` int(11) NOT NULL,
  PRIMARY KEY (`id`)

     */

    public function __construct($db, $logger)
    {

        $this->db = $db;
        $this->db->set_charset('utf8mb4');
        $this->db->query('SET NAMES utf8mb4');
        $this->logger = $logger;

    }

    public function getCategoryById()
    {
        $categories = array();

        $query_cat = 'select * from categories where id = ' . $this->id;
        $result_cat = $this->db->query($query_cat);

        while ($row = $result_cat->fetch_array()) {
            $categories[] = array('id' => $row['id'], 'name' => $row['name'], 'parent' => $row['parent'], 'isopen' => $row['isopen'],);
        }

        return $categories;
    }

    public function getFeedsByCategories()
    {
        $results = $this->db->query('SELECT * FROM categories c ORDER BY name ');
        while ($rows = $results->fetch_array()) {

            $resultsUnreadByFolder = $this->db->query('SELECT count(*) as unread
            FROM items le 
                inner join flux lfe on le.feed = lfe.id 
                inner join categories lfo on lfe.folder = lfo.id  
            where unread = 1 and lfo.id = ' . $rows['id']);

            while ($rowsUnreadByFolder = $resultsUnreadByFolder->fetch_array()) {
                $unreadEventsByFolder = $rowsUnreadByFolder['unread'];
            }

            $resultsFeedsByFolder = $this->db->query('SELECT fe.id as feed_id, fe.name as feed_name, fe.description as feed_description, fe.website as feed_website, fe.url as feed_url, fe.lastupdate as feed_lastupdate, fe.lastSyncInError as feed_lastSyncInError 
            FROM categories f 
                inner join flux fe on fe.folder = f.id 
            where f.id = ' . $rows['id'] . " order by fe.name");


            while ($rowsFeedsByFolder = $resultsFeedsByFolder->fetch_array()) {

                $resultsUnreadByFeed = $this->db->query('SELECT count(*) as unread FROM categories f inner join flux fe on fe.folder = f.id 
                inner join items e on e.feed = fe.id  where e.unread = 1 and fe.id = ' . $rowsFeedsByFolder['feed_id']);

                $unreadEventsByFeed = 0;

                while ($rowsUnreadByFeed = $resultsUnreadByFeed->fetch_array()) {
                    $unreadEventsByFeed = $rowsUnreadByFeed['unread'];
                }

                $feedsByCategories[] = array(
                    'id' => $rowsFeedsByFolder['feed_id'],
                    'name' => $rowsFeedsByFolder['feed_name'],
                    'description' => $rowsFeedsByFolder['feed_description'],
                    'website' => $rowsFeedsByFolder['feed_website'],
                    'url' => $rowsFeedsByFolder['feed_url'],
                    'lastupdate' => $rowsFeedsByFolder['feed_lastupdate'],
                    'lastSyncInError' => $rowsFeedsByFolder['feed_lastSyncInError'],
                    'unread' => $unreadEventsByFeed
                );
            }

            $categories[] = array(
                'id' => $rows['id'],
                'name' => $rows['name'],
                'parent' => $rows['parent'],
                'isopen' => $rows['isopen'],
                'unread' => $unreadEventsByFolder,
                'feeds' => $feedsByCategories
            );

            $feedsByCategories = null;
        }

        return $categories;
    }

    public function getAll()
    {
        $categories = array();

        $query_cat = 'select * from categories';
        $result_cat = $this->db->query($query_cat);

        while ($row = $result_cat->fetch_array()) {
            $categories[] = array('id' => $row['id'], 'name' => $row['name'], 'parent' => $row['parent'], 'isopen' => $row['isopen'],);
        }

        return $categories;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return mixed
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @param mixed $parent
     */
    public function setParent($parent)
    {
        $this->parent = $parent;
    }

    /**
     * @return mixed
     */
    public function getIsopen()
    {
        return $this->isopen;
    }

    /**
     * @param mixed $isopen
     */
    public function setIsopen($isopen)
    {
        $this->isopen = $isopen;
    }


}