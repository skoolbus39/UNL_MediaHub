<?php
abstract class UNL_MediaYak_Models_BaseFeed extends Doctrine_Record
{

  public function setTableDefinition()
  {
    $this->setTableName('feeds');
    $this->hasColumn('id',            'integer',   4,    array('unsigned' => 0, 'primary' => true, 'notnull' => true, 'autoincrement' => true));
    $this->hasColumn('title',         'string',    null, array('primary' => false, 'notnull' => true, 'autoincrement' => false));
    $this->hasColumn('description',   'string',    null, array('primary' => false, 'notnull' => false, 'autoincrement' => false));
    $this->hasColumn('uidcreated',    'string',    null, array('primary' => false, 'notnull' => true, 'autoincrement' => false));
    $this->hasColumn('datecreated',   'timestamp', null, array('primary' => false, 'notnull' => true, 'autoincrement' => false));
  }
  
}