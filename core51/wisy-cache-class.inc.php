<?php if( !defined('IN_WISY') ) die('!IN_WISY');

/******************************************************************************
 * WISY Caches
 ******************************************************************************
 * konkret gibt es die fogenden Caches:
 *
 * x_cache_search	dieser Cache wird komplett verworfen, wenn irgendwelche
 *                  Änderungen von der Redaktion vorgenommen werden; dieser
 *                  Cache ist also - zumindest in der Woche tagsüber - sehr
 *                  kurzlebig.  Außerdem wird der Cache bei den nächtlichen
 *                  Aufräumarbeiten komplett verworfen, damit Anfragen wie
 *                  "beginnt morgen" neu erzeugt werden können.
 * x_cache_rss		dieser Cache wird für RSS-Anfragen verwendet, er wird nicht
 *                  bei jeder Änderung der Redaktion verworfen, sondern nur
 *                  einmal nachts.
 ******************************************************************************
 * Details zu x_cache_search:            
 *
 * eine Log-Auswertung via $framework->log() am 18.09.2009 brachte die folgende 
 * Statistik:
 *
 * cleanups: 728  (wenn die WISY-Datenbank von der Redaktion geändert wird,
 *                wird der Cache verworfen)
 * inserts:  3801 (nach einer erfolgend Suche)
 * hits:     1780 (eine gesparte Suche)
 *
 * da ein Inserts und die Cleanups im Millisekonden bereich liegen, die Hits
 * aber im schnitt eine Sekunde dauern (speziell die häufigen Abfragen der 
 * Startseite dauern etwas, wenn es einen Portalfilter gibt), lohnt sind das 
 * ganze wohl:
 *
 * Rechnen wir mal mit 10ms je insert/cleanup
 *
 * "Ausgaben":     728+3801 * 10ms = 45290 ms = 45 Sekunden = <1 Minute
 * "Ersparnisse":  1780  * 1000ms = 1780000 ms = 1780 Sekunden = 29 Minute (!)
 ******************************************************************************/



class WISY_CACHE_CLASS
{
	var $framework;
	var $table;
	var $db;

	function __construct(&$framework, $param)
	{
	    // constructor
	    $this->framework			 =& $framework;
	    $this->table				= isset($param['table']) ? $param['table'] : '';
	    $this->itemLifetimeSeconds	= isset($param['itemLifetimeSeconds']) ? intval($param['itemLifetimeSeconds']) : null;
	    $this->storeBlobs			= isset($param['storeBlobs']) && $param['storeBlobs'] ? true : false;
	    $this->db 					= new DB_Admin();
	}
	
	function createKey($ckey)
	{
		$len = strlen($ckey);
		if( $len > 255 )
		{
			// otherwise, Mysql just truncates to 255 characters which may lead to duplicate entries, see 
			// https://mail.google.com/mail/#all/13147e7e50122ead
			return substr($ckey, 0, 111) . md5(substr($ckey, 111, $len-111-112)) . substr($ckey, -112);
		}
		else
		{
			return $ckey;
		}
	}
	
	function lookup($ckey)
	{
		$ckey = $this->createKey($ckey);
	
		$this->db->query("SELECT cvalue, cdateinserted FROM $this->table WHERE ckey='".addslashes($ckey)."';");
		if( $this->db->next_record() )
		{
			if( $this->itemLifetimeSeconds > 0 )
			{
			    $deleteIfOlder = ftime("%Y-%m-%d %H:%M:%S", time()-$this->itemLifetimeSeconds);
				if( $this->db->fcs8('cdateinserted') < $deleteIfOlder )
				{
					$this->db->query("DELETE FROM $this->table WHERE cdateinserted<'$deleteIfOlder';");
					return "";
				}
			}
			
			if( $this->storeBlobs )
				return $this->db->fcs8('cvalue');
			else
				return $this->db->fcs8('cvalue');
		}
		
		return "";
	}
	
	function insert($ckey, $cvalue)
	{
		$ckey = $this->createKey($ckey);

		$query = "INSERT INTO $this->table (ckey, cvalue, cdateinserted) VALUES ('" . addslashes($ckey) . "', '" . addslashes($cvalue) . "', '" . ftime("%Y-%m-%d %H:%M:%S") . "') 
				ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue), cdateinserted = VALUES(cdateinserted);";

		@$this->db->query($query);
	}
	
	function cleanup()
	{
		$this->db->query("TRUNCATE TABLE $this->table;");
	}
	
	function deleteOldEntries()
	{
	    $deleteIfOlder = ftime("%Y-%m-%d %H:%M:%S", time()-$this->itemLifetimeSeconds);
		$this->db->query("DELETE FROM $this->table WHERE cdateinserted<'$deleteIfOlder';");
	}
};
