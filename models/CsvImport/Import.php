<?php
/**
 * CsvImport_Import - represents a csv import event
 * 
 * @version $Id$ 
 * @package CsvImport
 * @author CHNM
 * @copyright Center for History and New Media, 2008-2011
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 **/
class CsvImport_Import extends Omeka_Record 
{ 

    const UNDO_IMPORT_LIMIT_PER_QUERY = 100;

    const STATUS_IN_PROGRESS_IMPORT = 'Import In Progress';
    const STATUS_COMPLETED_IMPORT = 'Completed Import';
    const STATUS_IN_PROGRESS_UNDO_IMPORT = 'Undo Import In Progress';
    const STATUS_COMPLETED_UNDO_IMPORT = 'Completed Undo Import';
    const STATUS_IMPORT_ERROR_INVALID_ITEM = 'Import Error: Invalid Item';
    const STATUS_IMPORT_ERROR_INVALID_FILE_DOWNLOAD = 
        'Import Error: Invalid File Download';

    public $csv_file_name;
    public $item_type_id;
    public $collection_id;
    public $added; 

    public $delimiter;
    public $is_public;
    public $is_featured;
    public $skipped_row_count = 0;
    public $status;
    public $serialized_column_maps;

    public $stop_on_file_error;

    protected $_csvFile;

    /**
     * An array of columnMaps, where each columnMap maps a column index number 
     * (starting at 0) to an element, tag, and/or file.
     *
     * @var array 
     */
    private $_columnMaps; 

    public function setItemsArePublic($flag)
    {
        $this->is_public = (boolean)$flag;
    }

    public function setItemsAreFeatured($flag)
    {
        $this->is_featured = (boolean)$flag;
    }

    public function setCollectionId($id)
    {
        $this->collection_id = (int)$id;
    }

    public function setColumnDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
    }

    public function setFilePath($path)
    {
        $this->csv_file_name = $path;
    }

    public function setItemTypeId($id)
    {
        $this->item_type_id = (int)$id;
    }

    public function setStatus($status)
    {
        $this->status = (string)$status;
    }

    public function setStopOnError($flag)
    {
        $this->stop_on_file_error = (boolean)$flag;
    }

    public function setColumnMaps($maps)
    {
        $this->_columnMaps = $maps;
    }

    protected function beforeSave()
    {
        $this->serialized_column_maps = serialize($this->getColumnMaps());
    }

    /**
     * Imports the csv file.  This function can only be run once.
     * To import the same csv file, you will have to
     * create another instance of CsvImport_Import and run doImport
     * 
     * @return boolean true if the import is successful, else false
     */
    public function doImport() 
    { 
        $this->_log("Started import at: %time%");
        $this->status = self::STATUS_IN_PROGRESS_IMPORT;
        $csvFile = $this->getCsvFile();
        $this->forceSave(); 

        $itemMetadata = array(
            'public'         => $this->is_public, 
            'featured'       => $this->is_featured, 
            'item_type_id'   => $this->item_type_id,
            'collection_id'  => $this->collection_id
        );

        $maps = $this->getColumnMaps();
        $rows = $csvFile->getRowIterator();
        $rows->skipInvalidRows(true);
        $this->_log("Item import loop started at: %time%");
        $this->_log("Memory usage: %memory%");
        $batchAt = 500;
        foreach($rows as $index => $row) {
            // Skip the header row.
            if ($index == 0) {
                continue;
            }
            $this->skipped_row_count = $rows->getSkippedCount();
            // Save the number of skipped rows at regular intervals.
            if ($index % $batchAt == 0) {
                $this->forceSave();    
            }
            try {
                $item = $this->addItemFromRow($row, $itemMetadata, $maps);
                if ($this->hasErrorStatus()) {
                    $this->forceSave();
                    return false;
                }
                if ($index % $batchAt == 0) {
                    $this->_log("Finished batch of $batchAt items at: %time%");
                    $this->_log("Memory usage: %memory%");
                }
                if ($item) {
                    release_object($item);
                }
            } catch (Exception $e) {
                $this->status = self::STATUS_IMPORT_ERROR_INVALID_ITEM;
                throw $e;
            }
        }

        $this->status = self::STATUS_COMPLETED_IMPORT;
        $this->forceSave();
        return true;
    }


    // adds an item based on the row data
    // returns inserted Item
    private function addItemFromRow($row, $itemMetadata, $maps) 
    {
        $result = $maps->map($row);
        $fileUrls = $result[CsvImport_ColumnMap::TARGET_TYPE_FILE];
        $elementTexts = $result[CsvImport_ColumnMap::TARGET_TYPE_ELEMENT];
        $tags = $result[CsvImport_ColumnMap::TARGET_TYPE_TAG];
        $item = insert_item(array_merge(array('tags' => $tags), $itemMetadata),
            $elementTexts);

        foreach($fileUrls as $url) {
            try {
                $file = insert_files_for_item($item, 
                    'Url', $url, 
                    array(
                        'ignore_invalid_files' => !$this->stop_on_file_error
                    )
                );
            } catch (Omeka_File_Ingest_InvalidException $e) { 
                if ($this->stop_on_file_error) {
                    $this->status = (string)$e;
                    $this->forceSave();
                    return false;
                } else {
                    $this->_log($e, Zend_Log::ERR);
                }
            }            
            release_object($file);
        }

        // Makes it easy to unimport the item later.
        $this->recordImportedItemId($item->id);
        return $item;
    }

    private function recordImportedItemId($itemId) 
    {
        $csvImportedItem = new CsvImport_ImportedItem();
        $csvImportedItem->setArray(array('import_id' => $this->id, 'item_id' => 
            $itemId));
        $csvImportedItem->forceSave();
    }

    public function getCsvFile() 
    {
        if (empty($this->_csvFile)) {
            $this->_csvFile = new CsvImport_File($this->csv_file_name,
                $this->delimiter);
        }
        return $this->_csvFile;
    }

    public function getColumnMaps() 
    {
        if($this->_columnMaps === null) {
            $columnMaps = unserialize($this->serialized_column_maps);
            if (!($columnMaps instanceof CsvImport_ColumnMap_Set)) {
                throw new UnexpectedValueException("Column maps must be "
                    . "an instance of CsvImport_ColumnMap_Set. Instead, the "
                    . "following was given: " . var_export($columnMaps, true));
            }
            $this->_columnMaps = $columnMaps;
        }

        return $this->_columnMaps;
    }

    public function undoImport() 
    {
        $this->status = self::STATUS_IN_PROGRESS_UNDO_IMPORT;
        $this->forceSave();

        $itemLimitPerQuery = self::UNDO_IMPORT_LIMIT_PER_QUERY;        
        $iit = $this->getTable('CsvImport_ImportedItem');
        $it = $this->getTable('Item');

        $sql = $iit->getSelect()->where('`import_id` 
            = ?')->limit($itemLimitPerQuery);
        $importedItems = $iit->fetchObjects($sql, array($this->id));

        while(count($importedItems) > 0) {
            foreach($importedItems as $importedItem) {
                $item = $it->find($importedItem->getItemId());
                if ($item) {
                    $item->delete();
                }
                $importedItem->delete();
            }
            $sql = $iit->getSelect()->where('`import_id` 
                = ?')->limit($itemLimitPerQuery);
            $importedItems = $iit->fetchObjects($sql, array($this->id));        
        } 

        $this->status = self::STATUS_COMPLETED_UNDO_IMPORT;
        $this->forceSave();
    }

    // returns true if the import has completed
    // else returns false
    public function isComplete() 
    {
        return (($this->status == self::STATUS_COMPLETED_IMPORT) || 
            ($this->status == self::STATUS_COMPLETED_UNDO_IMPORT));
    }

    public function getStatus() 
    {
        return $this->status;
    }

    public function hasErrorStatus()
    {
        return (($this->status == self::STATUS_IMPORT_ERROR_INVALID_ITEM) || 
            ($this->status == self::STATUS_IMPORT_ERROR_INVALID_FILE_DOWNLOAD));
    }

    // returns the number of items currently imported.  if a user undoes an 
    // import, it decreases the count to show the number of items left to 
    // unimport
    public function getImportedItemCount()
    {
        $iit = $this->getTable('CsvImport_ImportedItem');
        $sql = $iit->getSelectForCount()->where('`import_id` = ?');
        $importedItemCount = $this->getDb()->fetchOne($sql, array($this->id));
        return $importedItemCount;
    }

    public function getProgress()
    {
        $importedItemCount = $this->getImportedItemCount();
        $progress = "Imported: $importedItemCount / Skipped: {$this->skipped_row_count}";
        return $progress;
    }

    private function _log($msg, $priority = Zend_Log::DEBUG)
    {
        if ($logger = Omeka_Context::getInstance()->getLogger()) {
            if (strpos($msg, '%time%') !== false) {
                $msg = str_replace('%time%', Zend_Date::now()->toString(), $msg);
            }
            if (strpos($msg, '%memory%') !== false) {
                $msg = str_replace('%memory%', memory_get_usage(), $msg);
            }
            $logger->log('[CsvImport] ' . $msg, $priority);
        }
    }
}
