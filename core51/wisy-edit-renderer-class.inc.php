<?php if (!defined('IN_WISY')) die('!IN_WISY');


class WISY_EDIT_RENDERER_CLASS
{


    var $framework;
    var $tools;
    var $glossar;

    private WISY_KI_PYTHON_CLASS $pythonAPI;


    /**
     * URI of the WISY_KI-API.
     *
     * @var string
     */
    private string $api_endpoint;

    private $courseSkills;


    /**************************************************************************
     * Tools / Misc.
     *************************************************************************/

    function __construct(&$framework)
    {
        require_once('admin/config/codes.inc.php'); // needed for $codes_beginnoptionen, $codes_kurstage etc.
        require_once('admin/lang.inc.php');
        require_once('admin/table_def.inc.php');    // needed for db.inc.php
        require_once('admin/config/db.inc.php');    // needed for LOG_WRITER_CLASS
        require_once('admin/date.inc.php');
        require_once('admin/eql.inc.php');
        require_once('admin/classes.inc.php');
        require_once('admin/config/trigger_kurse.inc.php');

        require_once('wisy-ki-python-class.inc.php');


        // constructor
        $this->framework =& $framework;
        $this->tools =& createWisyObject('WISY_EDIT_TOOLS_CLASS', $this->framework);
        $this->glossar =& createWisyObject('WISY_GLOSSAR_RENDERER_CLASS', $this->framework);

        $this->pythonAPI = new WISY_KI_PYTHON_CLASS();

        if (!defined('WISYKI_API')) {
            throw new Exception('WISYKI_API not set in admin/config/config.inc.php');
        }
        $this->api_endpoint = WISYKI_API;

        // find out the "backward" location (where to go to if "OK" or "Cancel" is hit
        $this->bwd = $_REQUEST['bwd'];
        if ($this->bwd == '') {
            $this->bwd = 'search';
            if ($_SERVER['HTTP_REFERER'] != '') {
                $this->bwd = $_SERVER['HTTP_REFERER'];
            } else if ($_REQUEST['action'] == 'ek') {
                $this->bwd = 'k' . intval($_REQUEST['id']);
            } else if ($_REQUEST['action'] == 'ea') {
                $this->bwd = 'a' . intval($_SESSION['loggedInAnbieterId']);
            }
        }


        // read some settings
        $this->billingRenderer =& createWisyObject('WISY_BILLING_RENDERER_CLASS', $this->framework);
    }


    private function _anbieter_ini_read($key, $default = '')
    {
        $return_value = $default;

        if (!isset($this->_anbieter_ini_settings)) {
            $this->_anbieter_ini_settings = array();
            $db = new DB_Admin;
            $db->query("SELECT settings x FROM anbieter WHERE id=" . intval($_SESSION['loggedInAnbieterId']));
            if ($db->next_record()) {
                $this->_anbieter_ini_settings = explodeSettings($db->fcs8('x'));
            }
            // $db->close();
        }

        if (isset($this->_anbieter_ini_settings[$key])) {
            $return_value = $this->_anbieter_ini_settings[$key];
        }

        return $return_value;
    }

    private function _anbieter_ini_write()
    {
        if (isset($this->_anbieter_ini_settings)) {
            $data = '';
            ksort($this->_anbieter_ini_settings);
            reset($this->_anbieter_ini_settings);
            foreach ($this->_anbieter_ini_settings as $regKey => $regValue) {
                $regKey = strval($regKey);
                $regValue = strval($regValue);
                if ($regKey != '') {
                    $regValue = strtr($regValue, "\n\r\t", "   ");
                    $data .= "$regKey=$regValue\n";
                }
            }

            $db = new DB_Admin;
            $db->query("UPDATE anbieter SET settings='" . addslashes($data) . "' WHERE id=" . intval($_SESSION['loggedInAnbieterId']));
            // $db->close();
        }
    }

    function getAdminAnbieterUserIds()
    {
        // generische Anbieter ID für "grobe Änderungen" (20) und "Bagatelländerungen" (19)
        return array($this->getAdminAnbieterUserId20(), $this->getAdminAnbieterUserId19());
    }

    private function getAdminAnbieterUserId20()
    {
        return 20;
    }

    private function getAdminAnbieterUserId19()
    {
        return 19;
    }

    private function getAnbieterPwEinst()
    {
        if (!isset($this->cachePwEinst)) {
            $this->cachePwEinst = 0;
            $anbieter_id = intval($_SESSION['loggedInAnbieterId']);
            if ($anbieter_id) {
                $db = new DB_Admin;
                $db->query("SELECT pflege_pweinst FROM anbieter WHERE id=$anbieter_id;");
                if ($db->next_record()) {
                    $this->cachePwEinst = $db->fcs8('pflege_pweinst');
                }
                // $db->close();
            }
        }

        return $this->cachePwEinst;
    }

    private function canEditBagatelleOnly()
    {
        $pw_einst = $this->getAnbieterPwEinst();
        if (!($pw_einst & 1) /*check this for security reasons: normally, the user cannot even login without bit #0 set*/
            || ($pw_einst & 4)) {
            return true;
        }
        return false;
    }

    function checkEmptyOnNull($val, &$error_array, $error)
    {
        $val = str_replace(' ', '', strtolower($val));
        if ($val == 'k.a.' || $val == 'ka.' || $val == 'ka') {
            $val = '';
        }

        if (preg_match('/[^0-9]/', $val) || $val == '0') {
            $error_array[] .= $error;
            return 0;
        }
        return $val == '' ? 0 : intval($val);
    }

    function checkEmptyOnMinusOne($val, &$error_array, $error)
    {
        $val = str_replace(' ', '', strtolower($val));
        if ($val == 'k.a.' || $val == 'ka.' || $val == 'ka') {
            $val = '';
        }

        if (preg_match('/[^0-9]/', $val)) {
            $error_array[] .= $error;
            return 0;
        }
        return $val == '' ? -1 : intval($val);
    }

    function checkDate($val, &$error_array)
    {
        if ($val == 'tt.mm.jjjj') {
            $val = '';
        }

        $ret = sql_date_from_human($val, 'dateopt');
        if ($ret == '0000-00-00 00:00:00' && $val != '') {
            $error_array[] = "Fehler: Ung&uuml;ltiges Datum <i>" . htmlspecialchars($val) . "</i> - geben Sie das Datum bitte in der Form <i>tt.mm.jjjj</i> an.";
            return $ret;
        }

        return $ret;
    }

    function checkTime($val, &$error_array)
    {
        if ($val == 'hh:mm') {
            $val = '';
        }

        list($hh, $mm) = explode(':', $val);
        $hh = intval($hh);
        $mm = intval($mm);
        if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) {
            $error_array[] = "Fehler: Ung&uuml;ltige Zeitangabe <i>" . htmlspecialchars($val) . "</i> - geben Sie die Zeit bitte in der Form <i>hh:mm</i> an.";
            return $val; // error
        }

        if ($hh < 10) $hh = "0$hh";
        if ($mm < 10) $mm = "0$mm";
        return "$hh:$mm"; // success
    }

    /**
     * Code wurde an PHP 8.0 Syntax angepasst.
     * @param $kursId
     * @param $newStichwoerter
     * @param $eigenschaften
     * @return void
     */
    function saveStichwortArray($kursId, $newStichwoerter, $eigenschaften)
    {
        $db = new DB_Admin;
        // alle Foederungen loeschen
        $sql = "DELETE FROM kurse_stichwort WHERE primary_id =" . $kursId . " AND attr_id IN ( SELECT id FROM stichwoerter WHERE eigenschaften ='" . $eigenschaften . "');";
        $db->query($sql);
        foreach ($newStichwoerter as $newStichwort) {
            $sql = "SELECT MAX(structure_pos) AS sp FROM kurse_stichwort WHERE primary_id=" . $kursId . ";";
            $db->query($sql);
            $db->next_record();
            $structurePos = intval($db->fcs8('sp')) + 1;
            $sql = "INSERT INTO kurse_stichwort (primary_id, attr_id, structure_pos) VALUES(" . $kursId . "," . $newStichwort . "," . $structurePos . ");";
            $db->query($sql);
        }
    }

    function getEditHelpText($feld, $gid)
    {
        $glosarid = $this->framework->iniRead('onlinepflege.hinweis.' . $feld, '');
        if ($glosarid) {
            $gid = $glosarid;
        }
        $glossar = $this->glossar->getGlossareintrag($gid);
        return $glossar['erklaerung'];
    }

    /**
     * Code wurde an PHP 8.0 Syntax angepasst.
     * @param $kursId
     * @param $oldStichwId
     * @param $newStichwId
     * @return void
     */
    function saveStichwort($kursId, $oldStichwId, $newStichwId)
    {
        if ($oldStichwId == $newStichwId) {
            // nothing to do
            return;
        }
        $db = new DB_Admin;
        if ($oldStichwId > 0 && $newStichwId > 0) {
            // update stichwort
            $_SESSION['stockStichw'][$oldStichwId] = 1;
            $sql = "UPDATE kurse_stichwort SET attr_id=$newStichwId WHERE primary_id=" . $kursId . " AND attr_id=" . $oldStichwId . ";";
            $db->query($sql);
        } else if ($oldStichwId == 0 && $newStichwId > 0) {
            // add stichwort
            $sql = "SELECT MAX(structure_pos) AS sp FROM kurse_stichwort WHERE primary_id=" . $kursId . ";";
            $db->query($sql);
            $db->next_record();
            $structurePos = intval($db->fcs8('sp')) + 1;
            $sql = "INSERT INTO kurse_stichwort (primary_id, attr_id, structure_pos) VALUES(" . $kursId . "," . $newStichwId . "," . $structurePos . ");";
            $db->query($sql);
        } else if ($oldStichwId > 0 && $newStichwId == 0) {
            // delete stichwort
            $_SESSION['stockStichw'][$oldStichwId] = 1;
            $sql = "DELETE FROM kurse_stichwort WHERE primary_id=" . $kursId . " AND attr_id=" . $oldStichwId . ";";
            $db->query($sql);
        }
        // $db->close();
    }

    /** holt anhand des kuerzels aus der Themen tabelle die ID
     * @param $kuerzel
     * @return int
     */
    function searchThemaId($kuerzel)
    {
        $db = new DB_Admin;
        $sql = "SELECT id FROM themen WHERE kuerzel = '" . $kuerzel . "'";
        $result = $db->query($sql);

        if ($result && $db->num_rows($result) > 0) {
            $db->next_record();
            return intval($db->fcs8('id'));
        } else {
            return 0;
        }
    }


    /** holt aus der stichwort tabelle anhand von der ID den Namen.
     * @param $stichwortName
     * @return int
     */
    function searchWisyStichwortIdDB($stichwortName)
    {
        $db = new DB_Admin;
        $sql = "SELECT id FROM stichwoerter WHERE stichwort = '" . $stichwortName . "'";
        $result = $db->query($sql);

        if ($result && $db->num_rows($result) > 0) {
            $db->next_record();
            return intval($db->fcs8('id'));
            // return intval($db->fcs8('id'));
        } else {
            // Stichwort nicht gefunden
            return 0;
        }
    }

    /** prüft, ob die Kompetenzen bereits in der datenbank sind und legt diese an und verknüpft es mit dem kurs.
     * @param $kursId
     * @param $stichwort
     * @param $esco_url
     * @return void
     */
    function searchEscoStichwort($kursId, $stichwort, $esco_url)
    {
        $db = new DB_Admin;
        // Kontrollieren, ob die URL bereits vorhanden ist.
        $sqlUrlCheck = "SELECT id, stichwort, stichwort_sorted, zusatzinfo, eigenschaften, esco_url FROM stichwoerter WHERE esco_url ='" . $esco_url . "'";
        $resultUrlCheck = $db->query($sqlUrlCheck);
        if ($resultUrlCheck && $db->num_rows($resultUrlCheck) > 0) {
            //URL vorhanden
            $db->next_record() ? $newStichwId = intval($db->fcs8('id')) : $newStichwId = $esco_url;
            $sql = "SELECT MAX(structure_pos) AS sp FROM kurse_stichwort WHERE primary_id=" . $kursId . ";";
            $db->query($sql);
            $db->next_record();
            $structurePos = intval($db->fcs8('sp')) + 1;
            $sql = "INSERT INTO kurse_stichwort (primary_id, attr_id, structure_pos) VALUES(" . $kursId . "," . $newStichwId . "," . $structurePos . ");";
            $db->query($sql);
        } else {
            // esco begriff vorhanden
            $sqlValueCheck = "SELECT id, stichwort, stichwort_sorted, zusatzinfo, eigenschaften, esco_url FROM stichwoerter WHERE stichwort='" . $stichwort . "' AND esco_url != '" . $esco_url . "'";
            $resultValueCheck = $db->query($sqlValueCheck);
            if ($resultValueCheck && $db->num_rows($resultValueCheck) > 0) {
                $db->next_record() ? $newStichwId = intval($db->fcs8('id')) : $newStichwId = 'ERROR esco begriff';
                $sql = "SELECT MAX(structure_pos) AS sp FROM kurse_stichwort WHERE primary_id=" . $kursId . ";";
                $db->query($sql);
                $db->next_record();
                $structurePos = intval($db->fcs8('sp')) + 1;
                $sql = "INSERT INTO kurse_stichwort (primary_id, attr_id, structure_pos) VALUES(" . $kursId . "," . $newStichwId . "," . $structurePos . ");";
                $db->query($sql);
            } else {
                // if ($value == 'sachstichwort') continue;
                $timestamp = time();
                $creationDate = date("Y-m-d H:i:s", $timestamp);
                $user = $this->getAdminAnbieterUserId20();
                $anbieter = intval($_SESSION['loggedInAnbieterId']);
                $db->query("SELECT user_grp, user_access FROM anbieter WHERE id=$anbieter AND freigeschaltet = 1;");
                $db->next_record();
                $user_grp = intval($db->fcs8('user_grp'));
                $user_access = intval($db->fcs8('user_access'));
                $eigenschaften = 524288;
                $zusatzinfo = "WISY@KI: freigeschaltet=0 uri:" . $esco_url;
                $notizen = $creationDate . " ESCO Stichwort teilweise angelegt.";
                $sqlInsert = "INSERT INTO stichwoerter (date_created, date_modified, user_created, user_modified, user_grp, user_access, stichwort, stichwort_sorted, eigenschaften, zusatzinfo, notizen, esco_url)
VALUES('" . $creationDate . "','" . $creationDate . "'," . $user . "," . $user . "," . $user_grp . "," . $user_access . ",'" . $stichwort . "','" . addslashes(g_eql_normalize_natsort($stichwort)) . "'," . $eigenschaften . ",'" . $zusatzinfo . "','" . $notizen . "','" . $esco_url . "')";

                $resultInsert = $db->query($sqlInsert);
                if ($resultInsert) {
                    $lastInsertId = $db->insert_id();
                    $sql = "SELECT MAX(structure_pos) AS sp FROM kurse_stichwort WHERE primary_id=" . $kursId . ";";
                    $db->query($sql);
                    $db->next_record();
                    $structurePos = intval($db->fcs8('sp')) + 1;
                    $sql = "INSERT INTO kurse_stichwort (primary_id, attr_id, structure_pos) VALUES(" . $kursId . "," . $lastInsertId . "," . $structurePos . ");";
                    $db->query($sql);
                } else {
                    echo "SQL Error: " . $db->error(); // Debug-Ausgabe
                }
            }
        }
    }

    /** entfernt die verknüpfte Kompetenezn vom Kurs.
     * @param $kursId
     * @param $stichwort
     * @param $esco_url
     * @return void
     */
    function deleteEscoStichwort($kursId, $stichwort, $esco_url)
    {
        $db = new DB_Admin;
        $sqlUrlCheck = "SELECT id, stichwort, esco_url FROM stichwoerter WHERE esco_url ='" . $esco_url . "'";
        $resultUrlCheck = $db->query($sqlUrlCheck);
        if ($resultUrlCheck && $db->num_rows($resultUrlCheck) > 0) {
            //URL vorhanden
            $db->next_record() ? $newStichwId = intval($db->fcs8('id')) : $newStichwId = 'ERROR in loeschen';
            $sql = "DELETE FROM kurse_stichwort WHERE primary_id=" . $kursId . " AND attr_id=" . $newStichwId . ";";
            $db->query($sql);
        } else {
            $sqlValueCheck = "SELECT id, stichwort, esco_url FROM stichwoerter WHERE stichwort='" . $stichwort . "' AND esco_url != '" . $esco_url . "'";
            $resultValueCheck = $db->query($sqlValueCheck);
            if ($resultValueCheck && $db->num_rows($resultValueCheck) > 0) {
                $db->next_record() ? $newStichwId = intval($db->fcs8('id')) : $newStichwId = 'ERROR in loeschen else';
                $sql = "DELETE FROM kurse_stichwort WHERE primary_id=" . $kursId . " AND attr_id=" . $newStichwId . ";";
                $db->query($sql);
            }
        }
    }

    /** bearbeitet die erhaltenen stichwörter und verknüpft diese mit dem Kurs in der datenbank
     * @param $kursId
     * @param $oldStichwortArray
     * @param $newStichwortArray
     * @return void
     */
    function saveESCOstichwort($kursId, $oldStichwortArray, $newStichwortArray)
    {
        if (!empty($oldStichwortArray)) {
            foreach ($oldStichwortArray as $key => $value) {
                if ($value === 'sachstichwort') {
                    $keyId = $this->searchWisyStichwortIdDB($key);
                    $this->saveStichwort($kursId, $keyId, 0); // delete wisy-stw
                } else {
                    $this->deleteEscoStichwort($kursId, $key, $value);
                }
            }
        }

        if (!empty($newStichwortArray)) {// kontrollieren, ob die Kompetenz bereits in der db ist.
            foreach ($newStichwortArray as $key => $value) {
                if ($value === 'sachstichwort') {
                    $keyId = $this->searchWisyStichwortIdDB($key);
                    $this->saveStichwort($kursId, 0, $keyId);
                    // continue;
                } else {
                    $this->searchEscoStichwort($kursId, $key, $value);
                }
            }
        }
    }

    /** erzeugt die Kategorie liste aus der Datenbank
     * @param $checkboxValue
     * @return void
     */
    function selectCategory($checkboxValue = [])
    {
        $sql_category = array();
        $db = new DB_Admin();
        $sql = "SELECT id, stichwort FROM stichwoerter WHERE stichwort IN('Berufliche Bildung', 'Sprache', 'Andere') ORDER BY id;";
        $db->query($sql);

        while ($db->next_record()) {
            $id = $db->fcs8('id');
            $stichwort = $db->fcs8('stichwort');
            if ($stichwort != '')
                $sql_category[$id] = $stichwort;
        }
        $useredit_categoryhelp = $this->framework->iniRead('useredit.kategoriehelp', '');
        $useredit_categoryBK = $this->framework->iniRead('useredit.kategorieBK', '');
        $useredit_categorySK = $this->framework->iniRead('useredit.kategorieSK', '');
        $useredit_categoryAK = $this->framework->iniRead('useredit.kategorieAK', '');

        $useredit_categoriesExample = array('Berufliche Bildung' => $useredit_categoryBK, 'Sprache' => $useredit_categorySK, 'Andere' => $useredit_categoryAK);

        echo '<tr>';
        echo '<td valign="top"><strong>Kategorie<span style="color:red">*</span></strong>';
        if ($useredit_categoryhelp) echo $this->renderhoverInfo($useredit_categoryhelp);
        else echo $this->renderhoverInfo('Sofern zutreffend, empfehlen wir die Zuordnung Ihres Kurses zu Sprachlicher oder Beruflicher Bildung. Eine passende Kategorisierung erh&ouml;ht die Nutzerfreundlichkeit
                f&uuml;r Weiterbildungssuchende und verbessert die passgenaue Anzeige Ihres Kurses im Weiterbildungsscout. F&uuml;r Kurse die beiden Kategorien zugeordnet werden k&ouml;nnen: Bitte weisen Sie Ihren Kurs der Kategorie zu, die den Hauptanteil des Lerninhaltes ausmacht.
                F&uuml;r Kurse, die weder beruflicher noch sprachlicher Bildung zugeordnet werden k&ouml;nnen: Bitte vergeben Sie die Kategorie "Andere".');
        echo '</td><td><div class="wisy-kurstyp">';


        foreach ($sql_category as $key => $category) {
            echo '<label><input type="checkbox" name="kurs_category[]" id="';
            echo strtolower(str_replace(' ', '_', $category)) . '-checkbox" class="wisy-kategorie-check" value="';
            echo $key . '"' . (htmlentities(cs8($checkboxValue)) == $key ? 'checked' : '') . '>' . $category;

            if ($useredit_categoriesExample[$category]) {
                echo ' <small>' . $useredit_categoriesExample[$category] . '</small>';
            }
            echo '</label>';
        }
        echo '</div></td>';
        echo '</tr>';
    }

    /** holt anhand der ID den dazugehörigen Themen Begriff/Name.
     * @param $themaId
     * @return int
     */
    function getHauptthemaName($themaId)
    {
        $db = new DB_Admin;
        $sql = "SELECT thema FROM themen WHERE id = '" . $themaId . "'";
        $result = $db->query($sql);

        if ($result && $db->num_rows($result) > 0) {
            $db->next_record();
            return $db->fcs8('thema');
        } else {
            return 0;
        }
    }

    /** erzeugt die Hauptkategorien
     * @return void
     */
    function kuerzelHauptkategorie()
    {
        $db = new DB_Admin;
        $sql_hauptkategorie = array();
        $response = '';
        //  $sql = "SELECT id, kuerzel, thema FROM themen WHERE 1 AND id IN (258,60,235,233,311,371,5,7,16,126,234,74) ORDER BY thema_sorted;";
        $sql = "SELECT id, kuerzel, thema FROM themen WHERE 1 AND kuerzel_sorted IN (0000000002,0000000003,0000000004,0000000005,0000000006,0000000008,0000000009,0000000010,0000000011,0000000012,0000000013,0000000014) ORDER BY thema_sorted;";
        $db->query($sql);

        while ($db->next_record()) {
            $thema = $db->fcs8('thema');
            $kuerzel = $db->fcs8('kuerzel');
            if ($thema != '')
                $sql_hauptkategorie[$kuerzel] = $thema;
        }


        foreach ($sql_hauptkategorie as $key => $value) {
            $response .= '<option value="' . $key . '">' . $value . '</option>';
        }
        echo $response;
    }

    /** generiert anhand der Kursbeschreibung ESCO-Kompetenzen
     * @return false|void
     * @throws Exception
     */
    function generateEscoCompetence()
    {
        header("Content-Type: text/html; charset=UTF-8");
        $kursbeschreibung = $_POST['kursbeschreibung'];
        $esco_prediction = '';
        $response = '';
        $url = $this->api_endpoint . "/chatsearch";


        //    public function predict_esco_terms(string $title, string $description, string $thema, array $abschluesse, array $sachstichworte, array $filterconcepts = array(), int $strict = 2, int $requesttimeout = 20) {
        if (isset($kursbeschreibung)) {
            $response = $this->pythonAPI->predict_esco_terms('', $kursbeschreibung, '', [], []);
        }
        $res = array();
        if (isset($response['results'])) {
            $resu = $response['results'];
            if (is_array($resu))
                for ($i = 0; $i < sizeof((array)$resu); $i++) {
                    $res[] = $resu[$i];
                }
        } else
            return false;

        foreach ($res as $val) {
            if (is_array($val)) {
                $count = 0;
                // Eine weitere foreach-Schleife verwenden, um die inneren Arrays auszulesen
                foreach ($val as $innerVal) {
                    $count++;
                    if ($count == 3)
                        // $innerVal enthält die Werte innerhalb des inneren Arrays
                        $esco_prediction .= $innerVal . ' ';

                    if ($count == 4)
                        $esco_prediction .= '| ' . $innerVal . ' | ';
                }
            } else {
                // Wenn $val kein Array ist, können Sie es direkt verwenden
                $esco_prediction .= $val;
            }
        }
        echo $esco_prediction;
    }

    /** sendet per AJAX anhand von Kursbeschreibung und Lernziel eine Niveaustufe
     * @return void
     * @throws Exception
     */
    function handleKursniveaustufe(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Hier können Sie die JSON-Werte verarbeiten
            $courseTitle = $_POST['courseTitle'];
            $courseDesciption = $_POST['courseDescription'];

            $comp_level = $this->pythonAPI->predict_comp_level($courseTitle, $courseDesciption);

            echo $comp_level['level'];
        }
    }


// Beispiel-Funktion zum Verarbeiten der Checkbox-Daten und Rückgabe der Antwort

    /** holt per AJAX das Niveaustufenmodell der Kategorie
     * @return null
     */
    function handleCheckboxChange()
    {
        header("Content-Type: text/html; charset=UTF-8");

        $checkboxValues = $_POST['checkboxValues'];
        if (isset($checkboxValues)) {
            foreach ($checkboxValues as &$temp) {

                $db = new DB_Admin();
                $sql = "SELECT id, stichwort FROM stichwoerter WHERE id = " . $temp . ";";
                $db->query($sql);

                while ($db->next_record()) {
                    $id = $db->fcs8('id');
                    $stichwort = $db->fcs8('stichwort');
                    if ($stichwort != '')
                        $sql_category[$id] = $stichwort;
                }

                if (in_array('Berufliche Bildung', $sql_category)) $response = $this->createNiveaustufe();
                else if (in_array('Sprache', $sql_category)) $response = $this->sprachniveaus();
                else if (in_array('Andere', $sql_category)) $response = $this->createNiveaustufe();
            }
        }
        // Hier können Sie den erhaltenen Daten entsprechend Anweisungen ausführen und die Antwort generieren
        //$response = "Die Checkbox-Werte wurden erfolgreich verarbeitet.".$checkboxValues;
        return $response;
    }

    /** ruft die Unterthemen per AJAX auf
     * @return void
     */
    function handleThemaChange()
    {
        header("Content-Type: text/html; charset=UTF-8");

        $hauptThemaKuerzel = $_POST['hauptthema'];
        $db = new DB_Admin;
        $sql_unterkategorie = array();
        $response = '';
        // $sql_unterkategorie[0]='Bitte ausw&auml;hlen';

        //   $sql = "SELECT id, kuerzel, thema FROM themen WHERE 1 AND kuerzel LIKE '" . $hauptThemaKuerzel . "''%'";
        $sql = sprintf("SELECT id, kuerzel, thema FROM themen WHERE 1 AND kuerzel LIKE '%s%%'", $hauptThemaKuerzel);

        if ($hauptThemaKuerzel == '2.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '2.' && $kuerzel != '2.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '3.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '3.' && $kuerzel != '3.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '4.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '4.' && $kuerzel != '4.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '11.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '11.' && $kuerzel != '11.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '14.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '14.' && $kuerzel != '14.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '6.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '6.' && $kuerzel != '6.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '12.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '12.' && $kuerzel != '12.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '10.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '10.' && $kuerzel != '10.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '13.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '13.' && $kuerzel != '13.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '9.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '9.' && $kuerzel != '9.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '5.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '5.' && $kuerzel != '5.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        } else if ($hauptThemaKuerzel == '8.') {
            $db->query($sql);
            while ($db->next_record()) {
                $id = $db->fcs8('id');
                $kuerzel = $db->fcs8('kuerzel');
                $thema = $db->fcs8('thema');
                if ($kuerzel != '8.' && $kuerzel != '8.0.') {
                    $sql_unterkategorie[$id] = utf8_encode($thema);
                }
            }
        }

        $response .= '<option value="0">--- Bitte Thema ausw&auml;hlen ---</option>';
        foreach ($sql_unterkategorie as $key => $value) {
            $response .= '<option value="' . $key . '">' . $value . '</option>';
        }
        echo $response;
    }


    /*    function getAbschluesse()
        {
            $db = new DB_Admin;
            $sql_abschluss = array();
            // $sql = "SELECT stichwort FROM stichwoerter WHERE 1 AND eigenschaften = '16' OR eigenschaften = '1';";
            $sql = "SELECT stichwort FROM stichwoerter WHERE 1 AND eigenschaften = '1';";

            $db->query($sql);

            while ($db->next_record()) {
                $stichwort = $db->fcs8('stichwort');
                if ($stichwort != '') {
                    $sql_abschluss[] = $stichwort;
                }
            }

            foreach ($sql_abschluss as &$abschluss) {
                $var .= "<option>" . $abschluss . "</option>";
            }

            return $var;
        }*/

    /** holt die festgelegten Unterrichtsarten als Stichwort und erzeugt Checkboxen.
     * @param $checkboxValue
     * @return string
     */
    function getLernform($checkboxValue = [])
    {
        $db = new DB_Admin;
        $sql_lernformen = "";
        if ($this->framework->iniRead('useredit.lernform', '')) {
            $useredit_lernformen = $this->framework->iniRead('useredit.lernform', '');
            $useredit_lernformenArray = explode(',', $useredit_lernformen);
            if (is_array($useredit_lernformenArray)) {
                $sql_lernformen .= " AND id IN (" . implode(',', $useredit_lernformenArray) . ")";
            }
        }
        $sql = "SELECT id, eigenschaften, stichwort FROM stichwoerter WHERE 1" . $sql_lernformen . "ORDER BY stichwort_sorted;";
        $db->query($sql);

        while ($db->next_record()) {
            $stichwort = $db->fcs8('stichwort');
            $stichwid = intval($db->fcs8('id'));

            //    $text .= '<span class="wisy-lernform-span"><input class="wisy-lernform-checkbox" type="checkbox" name="useredit_stichwoerter[]" value="' . $stichwid . '"/><label for="' . $stichwid . '">' . $stichwort . '</label><input type="hidden" name="useredit_stichwoerterold[]" value="' . $stichwid . '"/></span><br>';
            $text .= '<span class="wisy-lernform-span"><input class="wisy-lernform-checkbox" id="' . $stichwid . '" type="checkbox" name="lernform_stichwort[]" value="' . $stichwid . '"/' . ($checkboxValue == $stichwid ? 'checked' : '') . '><label for="' . $stichwid . '">' . $stichwort . '<input type="hidden" name="useredit_stichwoerterold[]" value="' . $stichwid . '"/></label></span><br>';
        }
        return $text;
    }

    /** holt die festgelegten Foerderungen als Stichwort und erzeugt Checkboxen.
     * @param $checkboxValue
     * @return string
     */
    function foerderungen($checkboxValue = [])
    {
        $db = new DB_Admin;
        $sql_foerderungen = "";
        if ($this->framework->iniRead('useredit.foerderungen', '')) {
            $useredit_foerderungen = $this->framework->iniRead('useredit.foerderungen', '');
            $useredit_foerderungenArray = explode(',', $useredit_foerderungen);
            if (is_array($useredit_foerderungenArray)) {
                $sql_foerderungen .= " AND id IN (" . implode(',', $useredit_foerderungenArray) . ")";
            }
        }
        $sql = "SELECT id, eigenschaften, stichwort FROM stichwoerter WHERE 1" . $sql_foerderungen . "ORDER BY stichwort_sorted;";
        $db->query($sql);

        while ($db->next_record()) {
            $stichwort = $db->fcs8('stichwort');
            $stichwid = intval($db->fcs8('id'));
            $isChecked = in_array($stichwid, $checkboxValue) ? 'checked' : '';
            $text .= '<span class="wisy-foerderung-span"><input id="' . $stichwid . '" class="wisy-foerderungen-checkbox" type="checkbox" name="foerderung_stichwort[]" value="' . $stichwid . '"/' . $isChecked . '><label for="' . $stichwid . '">' . $stichwort . '</label><input type="hidden" name="useredit_stichwoerterold[]" value="' . $stichwid . '"/></span><br>';
        }
        return $text;
    }

    /** Extrahiert die Kurs-URL aus dem Bemerkungsfeld
     * @param $kursUrl
     * @return string
     */
    function filterKursUrl($kursUrl)
    {
        $startPos = strpos($kursUrl, "[[") + 2;
// Suchen Sie das Ende der URL
        $endPos = strpos($kursUrl, "|");

// Extrahieren Sie die URL
        $url = substr($kursUrl, $startPos, $endPos - $startPos);

        return $url;
    }

    /** extrahiert aus dem Bemerkungsfeld alle Texte ausser der Kurs-URL
     * @param $kursHinweise
     * @return array|string|string[]
     */
    function filterKursHinweis($kursHinweise)
    {
        $startPos = strpos($kursHinweise, "[[") + 2;
        // Überprüfen Sie, ob vor den "[[" ein "<br>" steht
        if (strpos(substr($kursHinweise, 0, $startPos), "<br>") !== false) {
            // Wenn ja, entfernen Sie das "<br>" aus dem extrahierten Text
            $textBeforeUrl = str_replace("<br>", "", substr($kursHinweise, 0, $startPos - 6));
        } else {
            // Wenn nicht, extrahieren Sie einfach den Text vor der URL
            $textBeforeUrl = substr($kursHinweise, 0, $startPos - 2);
        }
        return $textBeforeUrl;
    }


    function moeglicheAbschluesseUndFoerderungen(&$retAbschluesse, &$retFoerderungen)
    {
        $db = new DB_Admin;
        $sql_foerderungen = "";
        if ($this->framework->iniRead('useredit.foerderungen', '')) {
            $useredit_foerderungen = $this->framework->iniRead('useredit.foerderungen', '');
            $useredit_foerderungenArray = explode(",", $useredit_foerderungen);
            if (is_array($useredit_foerderungenArray)) {
                $sql_foerderungen .= " AND id IN (" . implode(",", $useredit_foerderungenArray) . ")";
            }
        }

        $db->query("SELECT id, eigenschaften, stichwort FROM stichwoerter WHERE 1 " . $sql_foerderungen . " ORDER BY stichwort_sorted;");
        while ($db->next_record()) {
            $id = intval($db->fcs8('id'));
            $eigenschaften = intval($db->fcs8('eigenschaften'));
            $stichwort = $db->fcs8('stichwort');
            if ($eigenschaften & 1) {
                $retAbschluesse .= ($retAbschluesse ? '###' : '') . $id . '###' . (PHP7 ? $stichwort : utf8_decode($stichwort));
            } else if ($eigenschaften & 2) {
                $retFoerderungen .= ($retFoerderungen ? '###' : '') . $id . '###' . (PHP7 ? $stichwort : utf8_decode($stichwort));
            }
        }

    }

    function moeglicheUnterrichtsarten(&$retUnterrichtsarten)
    {
        $db = new DB_Admin;
        $sql_unterrichtsarten = "";
        if ($this->framework->iniRead('useredit.unterrichtsarten', '')) {
            $useredit_unterrichtsarten = $this->framework->iniRead('useredit.unterrichtsarten', '');
            $useredit_unterrichtsartenArray = explode(",", $useredit_unterrichtsarten);
            if (is_array($useredit_unterrichtsartenArray)) {
                $sql_unterrichtsarten .= " AND id IN (" . implode(",", $useredit_unterrichtsartenArray) . ")";
            }
        }

        $db->query('SELECT id, eigenschaften, stichwort FROM stichwoerter WHERE eigenschaften = 32768 ' . $sql_unterrichtsarten . ' ORDER BY stichwort_sorted;');

        while ($db->next_record()) {
            $id = intval($db->fcs8('id'));
            $eigenschaften = intval($db->fcs8('eigenschaften'));
            $stichwort = $db->fcs8('stichwort');
            $retUnterrichtsarten .= ($retUnterrichtsarten ? '###' : '') . $id . '###' . (PHP7 ? $stichwort : utf8_decode($stichwort));
        }
    }


    function getStichwort($kursId, $StichwId)
    {
        $db = new DB_Admin;
        $db->query("SELECT id, eigenschaften, stichwort FROM stichwoerter WHERE id = $StichwId ;");
        $db->next_record();
        $stichwort = $db->fcs8('stichwort');
        $db->query("SELECT COUNT(primary_id)  AS anz FROM kurse_stichwort WHERE primary_id=$kursId AND attr_id = $StichwId;");
        $db->next_record();
        $anz = $db->fcs8('anz');
        $checked = " ";
        $text = '<input type="hidden" name="useredit_allstichwoerter[]" value="' . $StichwId . '"/>';
        if ($anz > 0) {
            $text .= '<tr><td>' . $stichwort . ':</td><td><input type="checkbox" name="useredit_stichwoerter[]" value="' . $StichwId . '" CHECKED/><input type="hidden" name="useredit_stichwoerterold[]" value="' . $StichwId . '"/></td></tr>';
        } else {
            $text .= '<tr><td>' . $stichwort . ':</td><td><input type="checkbox" name="useredit_stichwoerter[]" value="' . $StichwId . '" /></td></tr>';
        }
        return $text;

    }


    function testStichwort($kursId, $StichwId)
    {
        $db = new DB_Admin;
        $db->query("SELECT id, eigenschaften, stichwort FROM stichwoerter WHERE id = $StichwId ;");
        $db->next_record();
        $stichwort = $db->fcs8('stichwort');
        $db->query("SELECT COUNT(primary_id)  AS anz FROM kurse_stichwort WHERE primary_id=$kursId AND attr_id = $StichwId;");
        $db->next_record();
        $anz = $db->fcs8('anz');
        return $anz;
    }

    function controlHidden($name, $value)
    {
        echo "<input type=\"hidden\" name=\"$name\" value=\"" . htmlentities($value) . "\" />";
    }

    function controlText($name, $value, $size = 8, $maxlen = 255, $tooltip = '', $valuehint = '', $pattern = '', $placeholder = '', $required = 0, $type = 'text')
    {
        $em = intval($size * .5 + .5);
        $classname = str_replace('[]', "", $name);
        echo "<input class=\"wisy-kurs-$classname\" style=\"width: {$em}em; height: 30px; font-size: 14px\" type=\"$type\" name=\"$name\" value=\"" . htmlentities(cs8($value)) . "\" size=\"$size\" maxlength=\"$maxlen\" title=\"{$tooltip}\"";
        if ($pattern) {
            echo " pattern=\"$pattern\" ";
        }
        if ($placeholder) {
            echo " placeholder=\"$placeholder\" ";
        }
        if ($required == 1) {
            echo " required ";
        }
        #if( $valuehint) {
        #       echo " onfocus=\"if(this.value=='$valuehint'){this.value='';this.className='normal';}return true;\"";
        #       echo " onblur=\"if(this.value==''){this.value='$valuehint';this.className='wisy_hinted';}return true;\"";
        #       echo ($value==''||$value==$valuehint)? ' class="wisy_hinted"' : ' class="normal"';
        #}
        echo " />";
    }

    function controlSelect($name, $value, $values)
    {
        $values = explode('###', $values);

        echo "<select name=\"$name\" size=\"1\">";
        for ($v = 0; $v < sizeof($values); $v += 2) {
            echo '<option value="' . $values[$v] . '"';
            if ($values[$v] == $value) {
                echo ' selected="selected"';
            }
            echo '>' . htmlspecialchars(cs8($values[$v + 1])) . '</option>';
        }
        echo '</select>';
    }

    function controlMultiSelect($name, $value, $values)
    {
        global $edit_tagid_blacklist;

        $values = explode('###', $values);
        echo "<select name=\"" . $name . "[]\" size=\"8\" multiple>";

        for ($v = 0; $v < sizeof($values); $v += 2) {

            if (in_array($values[$v], $edit_tagid_blacklist)) // global black list of tag ids not to be displayed in selections
                continue;

            echo '<option value="' . $values[$v] . '"';
            if (is_array($value)) {
                if (in_array($values[$v], $value)) {
                    echo ' selected="selected"';
                }
            }
            echo '>' . htmlspecialchars(cs8($values[$v + 1])) . '</option>';
        }
        echo '</select>';
    }

    function getToolbar()
    {
        $ret = '';

        /*    $ret .= '<script src="https://cdn.tiny.cloud/1/pst7wgy5ivuhnkcu7q0akv4ca3bg6m3w7d3cqzqkshw8rd2o/tinymce/5/tinymce.min.js" referrerpolicy="origin"></script>';
            //  $ret .= '<script src="https://cdn.jsdelivr.net/npm/@tinymce/tinymce-jquery@2/dist/tinymce-jquery.min.js"></script>';
            $ret .= '<script src="https://cdn.tiny.cloud/1/pst7wgy5ivuhnkcu7q0akv4ca3bg6m3w7d3cqzqkshw8rd2o/tinymce/5/jquery.tinymce.min.js" referrerpolicy="origin"></script>';*/


        $ret .= '<div class="wisy_edittoolbar">';

        // name / logout link
        $name = $_SESSION['loggedInAnbieterSuchname'];
        $maxlen = 30;
        if (strlen($name) > $maxlen) $name = trim(substr($name, 0, $maxlen - 5)) . '..';
        $ret .= '<div style="float: right;">eingeloggt als: '
            . '<a href="' . $this->framework->getUrl('a', array('id' => $_SESSION['loggedInAnbieterId'], 'q' => $this->framework->getParam('q'))) . '?editstart=' . date("Y-m-d-h-i-s") . '">' . isohtmlspecialchars($name) . '</a>';

        if (strlen($_SESSION['loggedInAnbieterPflegemail']) > 3) {
            $ret .= ' | <a href="#" onclick=\'resetPassword(' . $_SESSION['loggedInAnbieterId'] . ', "' . substr($_SESSION['loggedInAnbieterPflegemail'], 0, 3) . '...'
                . substr($_SESSION['loggedInAnbieterPflegemail'], strlen($_SESSION['loggedInAnbieterPflegemail']) - 6) . '"); return false;\'>Neues Passwort anfordern!</a>';
        } else {
            if ($_SESSION['_login_as'])
                $ret .= ' <span class="pw_resetinfo">(Redaktionslogin)</span>';
            else
                $ret .= ' | <span class="pw_resetinfo">(F&uuml;r Passwort&auml;nderungen: Pflege-Email hinterlegen oder Portal-Betreiber schreiben.)</span>';
        }

        $ret .= ' | <a href="' . $this->framework->getUrl('edit', array('action' => 'logout')) . '">Logout</a>'
            . '</div>';


        $ret .= 'f&uuml;r Anbieter: ';

        // link "meine kurse"
        $q = $_SESSION['loggedInAnbieterTag'] . ', Datum:Alles';
        $ret .= '<a href="' . $this->framework->getUrl('search', array('q' => $q)) . '">Alle Kurse</a>';

        // link "kurs bearbeiten"
        global $wisyRequestedFile;
        if ($wisyRequestedFile[0] == 'k' && ($kursId = intval(substr($wisyRequestedFile, 1))) > 0) {
            $db = new DB_Admin;
            $db->query("SELECT anbieter FROM kurse WHERE id=$kursId");
            if ($db->next_record()) {
                if ($this->framework->getEditAnbieterId() == $db->fcs8('anbieter')) {
                    $ret .= ' | <a href="edit?action=ek&amp;id=' . $kursId . '">Kurs bearbeiten</a>';
                }
            }
            // $db->close();
        }


        /* else if( $wisyRequestedFile[0] == 'a' && ($_SESSION['loggedInAnbieterId'] == intval(substr($wisyRequestedFile, 1))))
             {
             $ret .= ' | <a href="edit?action=ea">Profil bearbeiten</a> ';
             } */

        // link "neuer kurs"
        $ret .= ' | <a href="edit?action=ek&amp;id=0">Neuer Kurs</a> ';

        /* if($_SESSION['loggedInAnbieterId'] > 0 && $_SESSION['loggedInAnbieterId'] < 99999999999)
             $ret .=  ' | <a href="/a'.$_SESSION['loggedInAnbieterId'].'">Zum Profil</a>'; */

        $ret .= ' | <a href="edit?action=ea">Ihr Profil bearbeiten</a> ';

        // link "hilfe"
        $ret .= ' | <a href="' . $this->framework->getHelpUrl($this->framework->iniRead('useredit.help', '3371')) . '" target="_blank" rel="noopener noreferrer">Hilfe</a> ';

        $ret .= '</div>';

        if ($_COOKIE['editmsg'] != '') {
            $ret .= "<p class=\"wisy_topnote\">" . $_COOKIE['editmsg'] . "</p>";
            $ret .= '<script>document.cookie = "editmsg=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";</script>'; // makes sense?
        }

        return $ret;
    }

    private
    function isEditable($kursId) /* returns 'yes', 'no' or 'loginneeded' */
    {
        if ($kursId == 0) {
            return 'yes'; // new kurs - this must be editable
        }

        $db = new DB_Admin;
        $db->query("SELECT anbieter, freigeschaltet, user_created FROM kurse WHERE id=$kursId;");
        if (!$db->next_record()) {
            // $db->close();
            return 'no'; // bad record ID - not editable
        }

        if ($db->fcs8('anbieter') != $_SESSION['loggedInAnbieterId']) {
            // $db->close();
            return 'loginneeded'; // may be editable, but bad login data
        }

        if ($db->fcs8('freigeschaltet') == 1 /*freigeschaltet*/
            || $db->fcs8('freigeschaltet') == 4 /*dauerhaft*/
            || $db->fcs8('freigeschaltet') == 3 /*abgelaufen*/
            || ($db->fcs8('freigeschaltet') == 0 /*in Vorbereitung*/)) // Kurse in Vorbereitung sollen ueber Direktlinks editierbar sein, daher an dieser Stelle keine ueberpruefung, ob der Kurs von getAdminAnbieterUserIds() angelegt wurde, s. https://mail.google.com/mail/#all/132aa92c4ec2cda7
        {
            // $db->close();
            return 'yes'; // editable
        }

        // $db->close();
        return 'no'; // not editable
    }


    /**************************************************************************
     * Login / Logout
     **************************************************************************/

    private
    function renderLoginScreen()
    {
        // see what to do
        $db = new DB_Admin;
        $fwd = 'search';
        $anbieterSuchname = '';
        $loginError = '';

        if ($_REQUEST['action'] == 'loginSubseq' && isset($_REQUEST['cancel'])) {
            header('Location: ' . $this->bwd);
            exit();
        } else if ($_REQUEST['action'] == 'loginSubseq') {
            // "OK" wurde angeklickt - loginversuch starten
            $fwd = strval($_REQUEST['fwd']);
            $anbieterSuchname = strval($_REQUEST['as']);
            $anbieterSuchname_utf8dec = (PHP7 ? $anbieterSuchname : utf8_decode($anbieterSuchname));

            $logwriter = new LOG_WRITER_CLASS;
            $logwriter->addData('ip', $_SERVER['REMOTE_ADDR']);
            $logwriter->addData('browser', $_SERVER['HTTP_USER_AGENT']);
            $logwriter->addData('portal', $GLOBALS['wisyPortalId']);

            $loggedInAnbieterId = 0;
            $loggedInAnbieterSuchname = 0;
            $loggedInAnbieterPflegemail = "";

            // Anbieter ID in name konvertieren
            if (is_numeric($anbieterSuchname_utf8dec)) {

                $db->query("SELECT suchname FROM anbieter WHERE id=" . intval($anbieterSuchname_utf8dec) . " AND freigeschaltet = 1"); // intval converts "4 abcdef" into "4" => is_numeric before
                if ($db->next_record()) {
                    $anbieterSuchname = $db->fcs8('suchname');
                    $anbieterSuchname_utf8dec = (PHP7 ? $anbieterSuchname : utf8_decode($anbieterSuchname));
                }

            } // end: is_numeric

            $login_as = false;
            if (($p = strpos(strval($_REQUEST['wepw']), '.')) !== false) {
                // ...Login als registrierter Admin-Benutzer in der Form "<loginname>.<passwort>"
                // KEINE Fehler fuer  diesen Bereich loggen - ansonsten wuerden wir u.U. Teile des Passworts loggen!
                $temp[0] = substr(strval($_REQUEST['wepw']), 0, $p);
                $temp[1] = substr(strval($_REQUEST['wepw']), $p + 1);

                $sql = "SELECT password, id FROM user WHERE loginname='" . addslashes($temp[0]) . "'";
                $db->query($sql);
                if ($db->next_record()) {
                    $dbPw = $db->fcs8('password');
                    if (crypt($temp[1], $dbPw) == $dbPw) {
                        require_once('admin/acl.inc.php');
                        if (acl_check_access('kurse.COMMON', -1, ACL_EDIT, $db->fcs8('id'))) {
                            $db->query("SELECT id FROM anbieter WHERE suchname='" . addslashes($anbieterSuchname_utf8dec) . "' AND freigeschaltet = 1");
                            if ($db->next_record()) {
                                $logwriter->addData('loginname', $temp[0] . ' as ' . $anbieterSuchname);
                                $loggedInAnbieterId = intval($db->fcs8('id'));
                                $login_as = true;
                            }
                        }
                    }
                }
            }

            if ($loggedInAnbieterId == 0) {
                // ...Login als normaler Anbieter in der Form "<passwort>"
                $logwriter->addData('loginname', $anbieterSuchname);
                $db->query("SELECT pflege_email, pflege_passwort, pflege_pweinst, id FROM anbieter WHERE suchname='" . addslashes($anbieterSuchname_utf8dec) . "' AND freigeschaltet = 1");
                if ($db->next_record()) {
                    $dbPw = $db->fcs8('pflege_passwort');
                    $dbPwEinst = intval($db->fcs8('pflege_pweinst'));
                    if (crypt(strval($_REQUEST['wepw']), $dbPw) == $dbPw
                        && $dbPwEinst & 1 /*freigeschaltet?*/) {
                        $loggedInAnbieterId = intval($db->fcs8('id'));
                        $loggedInAnbieterPflegemail = $db->fcs8('pflege_email');
                    } else {
                        $logwriter->addData('msg', 'Anbieter "' . $anbieterSuchname . '" hat ein falsches Passwort eingegeben.');
                    }
                } else {
                    $logwriter->addData('msg', 'Anbieter "' . $anbieterSuchname . '" existiert nicht.');
                }
            }

            if ($loggedInAnbieterId == 0) {
                $db->query("SELECT id FROM anbieter WHERE suchname='" . addslashes($anbieterSuchname_utf8dec) . "' AND freigeschaltet = 1");
                $db->next_record();

                $logwriter->log('anbieter', intval($db->fcs8('id')), $this->getAdminAnbieterUserId20(), 'loginfailed');

                $loginError = 'bad_pw';
            } else if ($_REQUEST['javascript'] != 'enabled') {
                $loginError = 'no_js';
            } else {
                $this->framework->startEditSession();
                $_SESSION['loggedInAnbieterId'] = $loggedInAnbieterId;
                $_SESSION['loggedInAnbieterPflegemail'] = $loggedInAnbieterPflegemail;
                $_SESSION['loggedInAnbieterSuchname'] = $anbieterSuchname;
                $_SESSION['loggedInAnbieterTag'] = g_sync_removeSpecialChars($anbieterSuchname);
                $_SESSION['_login_as'] = $login_as;

                $logwriter->log('anbieter', $loggedInAnbieterId, $this->getAdminAnbieterUserId20(), 'login');

                if ($fwd == 'search') {
                    $fwd = 'search?q=' . urlencode($anbieterSuchname . ', Datum:Alles'); // avoid using just /search which would lead to the homepage
                }

                $redirect = $fwd . (strpos($fwd, '?') === false ? '?' : '&') . ('bwd=' . urlencode($this->bwd));
                $protocol = $this->framework->iniRead('portal.https', '') ? "https" : "http";
                $redirect = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/' . $redirect; // back to a normal connection
                header("Location: $redirect");
                exit(); // success - login done!
            }
        } else {
            // erster Aufruf der Seite - initialisieren
            if ($_REQUEST['action'] == 'ek') {
                $sql = "SELECT suchname FROM anbieter LEFT JOIN kurse ON kurse.anbieter=anbieter.id WHERE kurse.id=" . intval($_REQUEST['id']) . " AND anbieter.freigeschaltet = 1";
                $db->query($sql);
                if ($db->next_record()) {
                    $anbieterSuchname = $db->fcs8('suchname');
                    $anbieterSuchname_utf8dec = (PHP7 ? $anbieterSuchname : utf8_decode($anbieterSuchname));
                }
                $fwd = "edit?action=ek&id=" . intval($_REQUEST['id']);
                $secureLogin = $fwd;
            } else {
                if (isset($_REQUEST['fwd'])) {
                    $fwd = $_REQUEST['fwd'];
                }
                $anbieterSuchname = $_REQUEST['as'];
                $anbieterSuchname_utf8dec = (PHP7 ? $anbieterSuchname : utf8_decode($anbieterSuchname));
                $secureLogin = "edit?action=login&as=" . urlencode($anbieterSuchname);
            }

            // first, see if we have to redirect to a secure login screen
            if ($this->framework->iniRead('useredit.secure', 1) == 1
                && substr($_SERVER['HTTP_HOST'], -6) != '.local'
                && $_SERVER['HTTPS'] != 'on') {
                $redirect = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $secureLogin;
                header("Location: $redirect");
                exit();
            }
        }

        // render login form

        echo $this->framework->getPrologue(array('title' => 'Login', 'bodyClass' => 'wisyp_edit'));
        echo $this->framework->getSearchField();

        echo '<section id="loginform" class="maxwidth">';
        echo '<h1>Login</h1>';

        $showLoginForm = true;
        if ($loginError == 'bad_pw') {
            echo '<p class="wisy_topnote"><b>Die Kombination aus Anbieter und Passwort ist unbekannt.</b> Bitte versuchen Sie es erneut. Beachten bei der Eingabe des Passwortes die Gro&szlig;-/Kleinschreibung und &uuml;berpr&uuml;fen Sie die Stellung der Feststelltaste.</p>';
        } else if ($loginError == 'no_js') {
            $url = 'edit?as=' . urlencode($anbieterSuchname) . '&fwd=' . urlencode($fwd) . '&bwd=' . urlencode($this->bwd);
            echo '<p class="wisy_topnote">Um alle Funktionen im Login-Bereich nutzen zu k&ouml;nnen, <b>aktivieren Sie bitte jetzt Javascript in Ihrem Browser.</b> '
                . 'Danach <a href="' . htmlspecialchars($url) . '">melden Sie sich bitte erneut an ...</a></p>';
            $showLoginForm = false;
        } else {
            echo '<p>Bitte geben Sie Ihre <b>Login-Daten</b> ein:</p>';
        }

        if ($showLoginForm) {
            echo '<form action="edit" method="post">';
            echo '<table>';
            echo "<input type=\"hidden\" name=\"action\" value=\"loginSubseq\" />";
            echo "<script type=\"text/javascript\"><!--\ndocument.write('<input type=\"hidden\" name=\"javascript\" value=\"enabled\" />');\n/" . "/--></script>";
            echo "<input type=\"hidden\" name=\"fwd\" value=\"" . htmlspecialchars(strval($fwd)) . "\" />";
            echo "<input type=\"hidden\" name=\"bwd\" value=\"" . htmlspecialchars(strval($this->bwd)) . "\" />";
            echo '<tr>';
            echo '<td nowrap="nowrap">Anbietername oder -ID:</td>';
            echo "<td><input type=\"text\" name=\"as\" value=\"" . htmlspecialchars($anbieterSuchname) . "\" size=\"50\" /></td>";
            echo '</tr>';
            echo '<tr>';
            echo '<td align="right">Passwort:</td>';
            echo '<td nowrap="nowrap">';
            echo '<input type="password" name="wepw" value="" size="30" />';
            // der Anbietername wird _nicht_ weitergegeben, damit ein Missbrauch mehr als nur einen Klick erfordert.
            echo ' <a href="' . htmlspecialchars($this->framework->getUrl('edit', array('action' => 'forgotpw' /*, 'as'=>$anbieterSuchname*/))) . '">Passwort vergessen?</a>';

            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td nowrap="nowrap">';
            echo '</td>';
            echo '<td nowrap="nowrap">';
            echo '<input type="submit" value="OK - Login" /> ';
            echo '<input type="submit" name="cancel" value="Abbruch" />';
            echo '</td>';
            echo '</tr>';
            echo '</table>';
            echo '</form>';

            // additional login message
            $temp = $this->framework->iniRead('useredit.loginmsg', '');
            if ($temp != '') {
                echo '<p class="loginmsg">' . $temp . '</p>';
            }
        }

        echo '</section><!-- /#loginform -->';

        // $db->close();

        echo $this->framework->getEpilogue();
    }

    function renderLogoutScreen()
    {
        $logwriter = new LOG_WRITER_CLASS;
        $logwriter->log('anbieter', $_SESSION['loggedInAnbieterId'], $this->getAdminAnbieterUserId20(), 'logout');

        session_destroy();
        setcookie($this->framework->editCookieName, '', 0, '/'); // remove cookie

        $redirect = "a" . $_SESSION['loggedInAnbieterId'];
        header('Location: ' . $redirect);
    }


    /**************************************************************************
     * Konto bearbeiten
     **************************************************************************/

    function renderEditKonto()
    {
        echo $this->framework->getPrologue(array('title' => 'Ihr Konto', 'bodyClass' => 'wisyp_edit'));
        // echo $this->framework->getSearchField();

        $credits = $this->promoter->getCredits($_SESSION['loggedInAnbieterId']);
        echo "\n\n<h1>Kontostand: $credits Einblendungen</h1>\n";

        echo "<p>";
        echo "Ihr aktuelles Guthaben betr&auml;gt <b>$credits Einblendungen</b>.";
        echo "</p>";

        echo "<p>";
        echo "Mit Ihren Einblendungen k&ouml;nnen Sie beliebige Kurse in den Suchergebnissen an die ersten Stellen bringen. ";
        echo $this->billingRenderer->allPrices[0][0] . " Einblendungen kosten aktuell&nbsp;<b>" . str_replace('.', ',', $this->billingRenderer->allPrices[0][1]) . "&nbsp;&euro;</b>.";
        echo "</p>";

        echo "\n\n<h1>Einblendungen kaufen</h1>\n";

        $this->billingRenderer->renderButton($_SESSION['loggedInAnbieterId']);

        echo "\n\n<h1>Beworbene Kurse</h1>\n";

        // WENN es kredite gibt, den Status der Tabelle anbieter_promote auf "aktiv" setzen, damit wieder Kurse geschaltet werden können
        $this->promoter->setAllPromotionsActive($_SESSION['loggedInAnbieterId'], $credits > 0 ? 1 : 0);


        echo '<br /><table border="1" cellspacing="0" cellpadding="6">';

        echo '<tr>';
        echo '<th>Titel</th>';
        echo '<th>Status</th>';
        echo '<th>Einstellung</th>';
        echo '</tr>';

        $showInactiveHints = false;
        $cnt = 0;
        global $wisyPortalId;
        $db = new DB_Admin;
        $db->query("SELECT kurse.id, titel, promote_active, promote_mode, promote_param FROM anbieter_promote LEFT JOIN kurse ON kurse.id=kurs_id WHERE anbieter_id=" . $_SESSION['loggedInAnbieterId'] . " AND portal_id=$wisyPortalId ORDER BY titel;");
        while ($db->next_record()) {
            $kurs_id = intval($db->fcs8('id'));
            $titel = $db->fcs8('titel');
            $promote_active = intval($db->fcs8('promote_active'));
            $promote_mode = $db->fcs8('promote_mode');
            $promote_param = $db->fcs8('promote_param');
            echo '<tr>';
            echo '<td valign="top">';

            echo '<a href="' . $this->framework->getUrl('edit', array('action' => 'ek', 'id' => $kurs_id)) . '">' . htmlspecialchars($titel) . '</a>';

            echo '</td><td valign="top" nowrap="nowrap">';

            if ($promote_active) {
                echo '<span style="color: #00C000;">Bewerbung aktiv</span>';
            } else {
                echo '<span style="color: #C00000;">Bewerbung inaktiv</span> (*)';
                $showInactiveHints = true;
            }


            echo '</td><td valign="top">';

            switch ($promote_mode) {
                case 'times':
                    echo "Bewerben mit $promote_param verbleibenden Einblendungen";
                    break;
                case 'date':
                    echo "Bewerben bis zum " . sql_date_to_human($promote_param, 'dateopt editable');
                    break;
            }

            echo '</td>';
            echo '</tr>';

            $cnt++;
        }

        if ($cnt == 0) {
            echo '<tr><td colspan="3"><i>Derzeit werden keine Kurse beworben.</i></td></tr>';
        }

        echo '</table>';
        if ($showInactiveHints) {
            echo "(*) wenn eine Bewerbung inaktiv ist liegt dies entweder daran, dass kein Kredit mehr zur Verf&uuml;gung steht oder dass die Bedingung f&uuml;r die Bewerbung abgelaufen ist";
        }

        echo "<p>";
        echo "Um einen Kurs zu bewerben, klicken Sie beim Bearbeiten eines Kurses einfach auf \"Diesen Kurs bewerben\". ";
        echo "Kurse, die Sie nicht explizit beworben werden, tauchen wie gewohnt in den Suchergebnissen auf. ";
        echo "</p>";

        // $db->close();

        echo $this->framework->getEpilogue();
    }

    /**************************************************************************
     * einzelnen Kurs bearbeiten / löschen
     **************************************************************************/

    function loadKursFromDb($kursId /* may be "0" for "new kurs"; defaults loaded in this case */)
    {
        // kurs inkl. aller durchführungen laden
        // 		das zurückgegebene Array ist wie bei loadKursFromPOST() beschrieben formatiert

        // kursdatensatz und alle durchfuehrungen lesen
        global $controlTags;
        $db = new DB_Admin;
        $db->query("SELECT * FROM kurse WHERE id=$kursId;");
        if ($db->next_record()) {
            $kurs = $db->Record;
            if ($db->fcs8('freigeschaltet') == 0 /*in vorbereitung*/ && $db->fcs8('user_created') == $this->getAdminAnbieterUserId20()) {
                $kurs['rights_editTitel'] = true;
                $kurs['rights_editAbschluss'] = true;
            }

            $kurs['durchf'] = array();
            $db->query("SELECT * FROM durchfuehrung LEFT JOIN kurse_durchfuehrung ON durchfuehrung.id=kurse_durchfuehrung.secondary_id WHERE kurse_durchfuehrung.primary_id=$kursId ORDER BY kurse_durchfuehrung.structure_pos;");
            while ($db->next_record()) {
                $kurs['durchf'][] = $db->Record;
            }
        } else if ($kursId == 0) {
            $kurs = array();
            $kurs['id'] = 0;
            $kurs['durchf'] = array();
            $kurs['durchf'][0]['id'] = 0;
            $kurs['rights_editTitel'] = true;
            $kurs['rights_editAbschluss'] = true;
        } else {
            $kurs['error'][] = 'Die angegebene Kurs-ID existiert nicht oder nicht mehr.';
            // $db->close();
            return $kurs;
        }

        // foerderung/abschlussart und Kategorie aus den stichwoertern extrahieren
        $kurs['category'] = 0;
        //   $kurs['abschluss'] = 0;
        //   $kurs['foerderung'] = array();
        //   $kurs['unterrichtsart'] = array();
        $kurs['lernform_stichwort'] = 0;
        $kurs['foerderung_stichwort'] = array();
        $kurs['niveau_stufe'] = array();
        $kurs['niveau_stw'] = array();
        $kurs['sprachlevel'] = array();
        $kurs['sprachlevel_stw'] = array();
        $kurs['kompetenzen'] = array();
        $kurs['einstieg'] = 0;
        $db->query("SELECT s.id, s.eigenschaften, s.stichwort, s.esco_url FROM stichwoerter s LEFT JOIN kurse_stichwort ks ON s.id=ks.attr_id WHERE ks.primary_id=$kursId ORDER BY ks.structure_pos;");
        while ($db->next_record()) {
//        SELECT s.id, s.eigenschaften, s.stichwort FROM stichwoerter s LEFT JOIN kurse_stichwort ks ON s.id=ks.attr_id WHERE ks.primary_id=1003993072 AND s.eigenschaften ORDER BY ks.structure_pos;
            $eigenschaften = intval($db->fcs8('eigenschaften'));
            $id = intval($db->fcs8('id'));
            $stichwort = $db->fcs8('stichwort');
            $esco_url = $db->fcs8('esco_url');
            //if ($eigenschaften & 1 && $kurs['abschluss'] == 0) $kurs['abschluss'] = $id;
            // if ($eigenschaften & 2) array_push($kurs['foerderung'], $id);
            //if ($eigenschaften == 32768) array_push($kurs['unterrichtsart'], $id);
            if ($stichwort == 'Andere' || $stichwort == 'Berufliche Bildung' || $stichwort == 'Sprache') $kurs['category'] = $id;
            if ($eigenschaften == 32768) $kurs['lernform_stichwort'] = $id;
            if ($eigenschaften == 2) array_push($kurs['foerderung_stichwort'], $id);
            if ($stichwort == 'Niveau A' || $stichwort == 'Niveau B' || $stichwort == 'Niveau C') array_push($kurs['niveau_stufe'], $id);
            if ($stichwort == 'Niveau A' || $stichwort == 'Niveau B' || $stichwort == 'Niveau C') array_push($kurs['niveau_stw'], $stichwort);
            if ($stichwort == 'A1' || $stichwort == 'A2' || $stichwort == 'B1' || $stichwort == 'B2' || $stichwort == 'C1' || $stichwort == 'C2') array_push($kurs['sprachlevel'], $id);
            if ($stichwort == 'A1' || $stichwort == 'A2' || $stichwort == 'B1' || $stichwort == 'B2' || $stichwort == 'C1' || $stichwort == 'C2') array_push($kurs['sprachlevel_stw'], $stichwort);
            if ($eigenschaften == 524288) $kurs['kompetenzen'][$stichwort] = $esco_url;
            if ($id == $controlTags['Einstieg bis Kursende moeglich']) $kurs['einstieg'] = $id;
            if (!(
                $stichwort == 'Andere' ||
                $stichwort == 'Berufliche Bildung' ||
                $stichwort == 'Sprache' ||
                $eigenschaften == 32768 ||
                $eigenschaften == 2 ||
                ($stichwort == 'Niveau A' || $stichwort == 'Niveau B' || $stichwort == 'Niveau C') ||
                ($stichwort == 'A1' || $stichwort == 'A2' || $stichwort == 'B1' || $stichwort == 'B2' || $stichwort == 'C1' || $stichwort == 'C2') ||
                ($eigenschaften == 524288) ||
                ($eigenschaften == 1024)
            )) {
                $kurs['kompetenzen'][$stichwort] = 'sachstichwort';
            }
        }


        // kreditinformationen laden
        global $wisyPortalId;
        $db->query("SELECT * FROM anbieter_promote WHERE kurs_id=$kursId AND portal_id=$wisyPortalId");
        $db->next_record(); // may be unexistant ... in this case the lines below evaluate to zero/emptyString
        $kurs['promote_active'] = intval($db->fcs8('promote_active'));
        $kurs['promote_mode'] = $db->fcs8('promote_mode');
        $kurs['promote_param'] = $db->fcs8('promote_param');

        // $db->close();

        return $kurs;
    }

    function loadKursFromPOST($kursId /* may be "0" for "new kurs" */)
    {
        // kurs aus datanbank laden und mit den POST-Daten aktualisieren
        //
        // kurs ist ein array wie folgt:
        // 		$kurs['titel']
        // 		$kurs['beschreibung']
        //      $kurs['bu_nummer']
        //      $kurs['fu_knr']
        //      $kurs['azwv_knr']
        //		$kurs['msgtooperator']
        //		$kurs['error'][]					(array mit Fehlermeldungen)
        // 		$kurs['durchf'][0]['nr']
        // 		$kurs['durchf'][0]['beginn']		(und 'ende', 'zeit_von', 'zeit_bis', 'kurstage', 'beginnoptionen')
        // 		$kurs['durchf'][0]['stunden']
        // 		$kurs['durchf'][0]['teilnehmer']
        // 		$kurs['durchf'][0]['preis']			(und 'sonderpreis', 'sonderpreistage', 'preishinweise')
        // 		$kurs['durchf'][0]['strasse']		(und 'plz', 'ort', 'stadtteil', 'bemerkungen')

        $kurs = $this->loadKursFromDb($kursId);
        if (sizeof((array)$kurs['error']))
            return $kurs;

        /*  error_reporting(E_ALL);
          ini_set('display_errors', 1);*/

        if (isset($_POST['kurs_category'])) {
            foreach ($_POST['kurs_category'] as $selectedCategory) {
                //  echo "ausgewählte Checkbox: " . $selectedCategory .'<br>';
                $kurs['category'] = $selectedCategory;
            }
        }
        $kurs['titel'] = $_POST['titel'];
        $kurs['beschreibung'] = $_POST['beschreibung'];
        $kurs['lernziele'] = $_POST['lernziele'];

        if (isset($_POST['selectedThema']) && $_POST['selectedThema'] !== 0) {
            $kurs['thema'] = $_POST['selectedThema'];
        } elseif (isset($_POST['unterkategorie']) && $_POST['unterkategorie'] !== '0') {
            $kurs['thema'] = $_POST['unterkategorie'];
            //$kurs['thema'] = $_POST['hauptkategorie'];
        } elseif (isset($_POST['hauptkategorie']) && $_POST['hauptkategorie'] !== '0') {
            $kurs['thema'] = $this->searchThemaId($_POST['hauptkategorie']);
        } else {
            $kurs['thema'] = 0;
        }

        $kurs['bu_nummer'] = $_POST['foerderung_bn'];
        $kurs['fu_knr'] = $_POST['fu_knr'];
        $kurs['azwv_knr'] = $_POST['foerderung_azav'];
        $kurs['msgtooperator'] = $_POST['msgtooperator'];
        $kurs['niveau_stufe'] = $_POST['niveau_stufe'];
        $kurs['sprachlevel'] = $_POST['sprachlevel'];
        $kompetenzVorschlag = [];
        if ($_POST['kompetenzen_stichwort']) {
            foreach ($_POST['kompetenzen_stichwort'] as $item) {
                $parts = explode('+++', $item);
                // Falls genügend Teile vorhanden sind, füge sie zum neuen Array hinzu
                if (count($parts) == 2) {
                    $key = $parts[0]; //stichwort begriff
                    $value = $parts[1]; // typ esco uri oder wisy sachstichwort
                    $kompetenzVorschlag[$key] = $value;
                }
            }
            $kurs['kompetenzen'] = $kompetenzVorschlag;
        }
        if (isset($_POST['lernform_stichwort'])) {
            foreach ($_POST['lernform_stichwort'] as $selectedLernform) {
                $kurs['lernform_stichwort'] = $selectedLernform;
            }
        }
        $kurs['foerderung_stichwort'] = $_POST['foerderung_stichwort'];
        $kurs['einstieg'] = $_POST['einstieg'];

        $kurs['durchf'] = array();
        for ($i = 0; $i < sizeof((array)$_POST['nr']); $i++) {
            // id, if any (may be 0 for copied areas)
            $kurs['durchf'][$i]['id'] = intval($_POST['durchfid'][$i]);

            // nr
            $posted = $_POST['nr'][$i];
            if (preg_match('/^k\s*\.?\s*\s*a\.?$/i' /*k. A.*/, $posted)) {
                $posted = '';
            };
            $kurs['durchf'][$i]['nr'] = $posted;

            // datum
            $kurs['durchf'][$i]['beginn'] = $this->checkDate($_POST['beginn'][$i], $kurs['error']);
            $kurs['durchf'][$i]['ende'] = $this->checkDate($_POST['ende'][$i], $kurs['error']);

            $kurs['durchf'][$i]['zeit_von'] = $this->checkTime($_POST['zeit_von'][$i], $kurs['error']);
            $kurs['durchf'][$i]['zeit_bis'] = $this->checkTime($_POST['zeit_bis'][$i], $kurs['error']);

            $kurs['durchf'][$i]['kurstage'] = intval(0);
            global $codes_kurstage;
            $bits = explode('###', $codes_kurstage);
            for ($j = 0; $j < sizeof($bits); $j += 2) {
                if (intval($_POST["kurstage$j"][$i]) == 1) {
                    $kurs['durchf'][$i]['kurstage'] |= intval($bits[$j]);
                }
            }

            $kurs['durchf'][$i]['beginnoptionen'] = intval($_POST['beginnoptionen'][$i]);
            $kurs['durchf'][$i]['dauer'] = intval($_POST['dauer'][$i]);
            $kurs['durchf'][$i]['tagescode'] = intval($_POST['tagescode'][$i]);

            $kurs['durchf'][$i]['rollstuhlgerecht'] = intval($_POST['rollstuhlgerecht'][$i]);
            // stunden
            $kurs['durchf'][$i]['stunden'] = $this->checkEmptyOnNull($_POST['stunden'][$i], $kurs['error'], "Fehler: Ung&uuml;ltiger Wert f&uuml;r die Unterrichtsstunden; wenn Sie die Anzahl der Unterrichtsstunden nicht wissen, lassen Sie dieses Feld leer.");

            // teilnehmer
            $kurs['durchf'][$i]['teilnehmer'] = $this->checkEmptyOnNull($_POST['teilnehmer'][$i], $kurs['error'], "Fehler: Ung&uuml;ltiger Wert f&uuml;r die Teilnehmenden; wenn Sie die Anzahl der Teilnehmenden nicht wissen, lassen Sie dieses Feld leer.");

            // preis
            $kurs['durchf'][$i]['preis'] = $this->checkEmptyOnMinusOne($_POST['preis'][$i], $kurs['error'], "Fehler: Ung&uuml;ltiger Wert f&uuml;r den Preis; wenn Sie den Preis nicht kennen, lassen Sie dieses Feld leer; f&uuml;r &quot;kostenlos&quot; verwenden Sie bitte den Wert 0.");
            $kurs['durchf'][$i]['sonderpreis'] = $this->checkEmptyOnMinusOne($_POST['sonderpreis'][$i], $kurs['error'], "Fehler: Ung&uuml;ltiger Wert f&uuml;r den Sonderpreis; wenn Sie den Sonderpreis nicht verwenden m&ouml;chten, lassen Sie dieses Feld leer.");
            $kurs['durchf'][$i]['sonderpreistage'] = $this->checkEmptyOnNull($_POST['sonderpreistage'][$i], $kurs['error'], "Fehler: Ung&uuml;ltiger Wert f&uuml;r die Tage beim Sonderpreis; wenn Sie den Sonderpreis nicht verwenden m&ouml;chten, lassen Sie dieses Feld leer.");
            $kurs['durchf'][$i]['preishinweise'] = $_POST['preishinweise'][$i];

            // ort
            $posted = $_POST['strasse'][$i];
            if ($posted == 'Strasse und Hausnr.') $posted = '';
            $kurs['durchf'][$i]['strasse'] = $posted;

            $posted = $_POST['plz'][$i];
            if ($posted == 'PLZ') $posted = '';
            $kurs['durchf'][$i]['plz'] = $posted;

            $posted = $_POST['ort'][$i];
            if ($posted == 'Ort') $posted = '';
            $kurs['durchf'][$i]['ort'] = $posted;

            if (($kurs['durchf'][$i]['strasse'] . ',' . $kurs['durchf'][$i]['plz'] . ',' . $kurs['durchf'][$i]['ort']) == $_POST['stadtteil_for'][$i]) {
                $kurs['durchf'][$i]['stadtteil'] = $_POST['stadtteil'][$i];
            } else {
                $kurs['durchf'][$i]['stadtteil'] = '';
            }

            $posted = $_POST['bemerkungen'][$i];
            $kurs['durchf'][$i]['bemerkungen'] = $posted . '<br>';

            $posted = $_POST['url'][$i];
            $kursurltitel = $this->framework->iniRead('useredit.KursUrlTitel', '') ? $this->framework->iniRead('useredit.KursUrlTitel', '') : "zur Anmeldung";
            $posted = "[[" . $posted . "|'''" . $kursurltitel . "''']]";
            $kurs['durchf'][$i]['bemerkungen'] .= $posted;

            // additional data validation
            if ($kurs['durchf'][$i]['ende'] != '0000-00-00 00:00:00' && $kurs['durchf'][$i]['beginn'] != '0000-00-00 00:00:00'
                && $kurs['durchf'][$i]['ende'] < $kurs['durchf'][$i]['beginn']) {
                $kurs['error'][] = "Fehler: Durchf&uuml;hrung " . ($i + 1) . ": Das Enddatum muss NACH dem Beginndatum liegen.";
            }

            $today = strftime("%Y-%m-%d %H:%M:%S");
            if (($kurs['durchf'][$i]['ende'] != '0000-00-00 00:00:00' && $kurs['durchf'][$i]['ende'] < $today)
                || ($kurs['durchf'][$i]['beginn'] != '0000-00-00 00:00:00' && $kurs['durchf'][$i]['beginn'] < $today)) {
                $kurs['info'][] = "Kursbeginn und/oder Kursende liegen in der Vergangenheit.";
            }

            if (($kurs['durchf'][$i]['sonderpreis'] >= 0 && $kurs['durchf'][$i]['sonderpreistage'] == 0)
                || ($kurs['durchf'][$i]['sonderpreis'] == -1 && $kurs['durchf'][$i]['sonderpreistage'] > 0)
                || ($kurs['durchf'][$i]['sonderpreis'] >= 0 && $kurs['durchf'][$i]['preis'] == -1)) {
                $kurs['error'][] = "Fehler: Um den Sonderpreis zu aktivieren, m&uuml;ssen Sie neben dem regul&auml;ren Preis die Anzahl der Tage und den Sonderpreis angeben.";
            } else if ($kurs['durchf'][$i]['sonderpreis'] >= 0 && $kurs['durchf'][$i]['sonderpreis'] >= $kurs['durchf'][$i]['preis']) {
                $kurs['error'][] = "Fehler: Der Sonderpreis muss kleiner als der regul&auml;re Gesamtpreis sein.";
            }
        }

        // additional data validation
        if ($kurs['rights_editTitel']) {
            if ($kurs['titel'] == '') {
                $kurs['error'][] = 'Fehler: Kein Kurstitel angegeben.';
            } else if ($kursId == 0) // neuer Kurs?
            {
                $db = new DB_Admin;
                $db->query("SELECT id FROM kurse WHERE freigeschaltet IN (0,1,3,4) AND titel=" . $db->quote(trim($kurs['titel'])) . " AND anbieter=" . intval($_SESSION['loggedInAnbieterId']));
                if ($db->next_record())            // ^^^^^^^^^^^^^^^^^^^^^^^^^^^ otherwise, if there is a deleted and an available offer, we may get the deleted one - which is not editable!
                {
                    $andere_kurs_id = $db->fcs8('id');
                    if ($this->isEditable($andere_kurs_id) == 'yes') {
                        // meine knappe Variante wäre gewesen: "Ein Kurse mit dem Titel <i><titel><i> <b>ist bereits vorhanden.</b> Bitte ändern Sie den bestehenden Kurs und fügen dort ggf. Durchführungen hinzu. <a>bestehenden Kurs bearbeiten</a>"
                        $otherUrl = $this->framework->getUrl('edit', array('action' => 'ek', 'id' => $andere_kurs_id));
                        $kurs['error'][] =
                            '
							Fehler: Ein Kurs mit dem Titel <i>' . htmlspecialchars($kurs['titel']) . '</i> <b>ist bereits vorhanden</b>.
							Um Verwirrungen zu vermeiden, k&ouml;nnen Sie das folgende tun:<br /><br />

							&bull; <b>Sie wollen weitere Termine des Kurses angelegen?</b> <a href="' . $otherUrl . '">Gehen Sie zum bereits vorhandenen Kurs</a> -
							eventuell ist er nur abgelaufen. Geben Sie beim vorhandenen Kurs in der Durchf&uuml;hrung die neuen Termine ein.
							Mit Klick  auf &quot;Durchf&uuml;hrung duplizieren&quot; k&ouml;nnen Sie mehrere Termine, auch an unterschiedlichen Orten, an den
							Kurs anh&auml;ngen. Falls erforderlich, k&ouml;nnen Sie auch die Kursbeschreibung aktualisieren.<br /><br />

							&bull; <b>Soll der neue Kurs eine v&ouml;llig andere Kursbeschreibung erhalten als der schon vorhandene Kurs?</b>
							W&auml;hlen Sie f&uuml;r diesen Kurs einen Titel, der ihn vom vorhandenen Kurs unterscheidet. Eventuell reicht es ja,
							einfach nur eine Zahl anh&auml;ngen, z.B. Englisch 1 und Englisch 2.<br /><br />

							&bull; <b>Soll der neue Kurs nur eine kleine &Auml;nderung im Titel erhalten, inhaltlich aber gleich bleiben?</b>
							Senden Sie einfach den gew&uuml;nschten neuen Titel per E-Mail an den Tr&auml;ger dieser Datenbank. Die
							Datenredaktion kann den Titel f&uuml;r Sie &auml;ndern; dann m&uuml;ssen Sie nicht alle Angaben zum Kurs komplett neu eingeben.<br />
							';
                    }
                }
                // $db->close();
            }

        }

        if ($kurs['category'] == 0) {
            $kurs['error'][] = 'Fehler: Der Kurs muss einer Kategorie zugewiesen werden.';
        }

        if ($kurs['beschreibung'] == '') {
            $kurs['error'][] = 'Fehler: Keine Kursbeschreibung angegeben.';
        }

        /*       if ($kurs['lernform_stichwort'] == 0) {
                   $kurs['error'][] = 'Fehler: Der Kurs hat keine Lernform.';
               }*/

        if (sizeof((array)$kurs['durchf']) < 1) {
            $kurs['error'][] = 'Fehler: Der Kurs muss mindestens eine Durchf&uuml;hrung haben.';
        }

        $max_df = $this->framework->iniRead('useredit.durchf.max', 25);
        if (sizeof((array)$kurs['durchf']) > $max_df) {
            $kurs['error'][] = '
								Fehler: <b>Die Anzahl &uuml;berschaubarer Durchf&uuml;hrungen ist &uuml;berschritten</b> -
								erlaubt sind maximal ' . $max_df . ' Durchf&uuml;hrungen pro Kurs; der aktuelle Kurs hat jedoch ' . sizeof($kurs['durchf']) . ' Durchf&uuml;hrungen.<br />
								Bei h&auml;ufigeren Beginnterminen w&auml;hlen Sie bitte eine Terminoption wie beispielsweise <i>Beginnt laufend</i> oder <i>Beginnt w&ouml;chentlich</i>
								und denken Sie auch daran, abgelaufene Durchf&uuml;hrungen zu l&ouml;schen.<br />
								';
        }

        // new 20:55 01.06.2014: es ist nur eine URL erlaubt - entweder in der Kursbeschreibung oder in den Durchführungsbemerkungen
        $stopwords = $this->tools->loadStopwords('useredit.stopwords');
        $maxlen_preishinweise = 160;
        $maxlen_bemerkungen = 250;
        $has_durchf_urls = false;
        foreach ($kurs['durchf'] as $durchf) {
            $durchf_urls = $this->tools->getUrls($durchf['preishinweise']);
            if (sizeof($durchf_urls)) {
                $kurs['error'][] = 'Fehler: Im Feld <i>Preishinweise</i> sind keine URLs erlaubt.';
            }
            if (strlen($durchf['preishinweise']) > $maxlen_preishinweise) {
                $kurs['error'][] = 'Fehler: Im Feld <i>Preishinweise</i> sind max. ' . $maxlen_preishinweise . ' Zeichen erlaubt. Eingegebene Zeichen: ' . strlen($durchf['preishinweise']);
            }

            $check_maxlen_bemerkungen = 0;
            $durchf_urls = $this->tools->getUrls($durchf['bemerkungen']);
            if (sizeof($durchf_urls)) {
                $has_durchf_urls = true;
                if (sizeof($durchf_urls) > 1) {
                    $kurs['error'][] = 'Fehler: Pro Feld <i>Bemerkungen</i> ist nur eine URL erlaubt. Gefundene URLs: ' . implode(', ', $durchf_urls);
                } else if ($this->tools->isAnbieterUrl($durchf_urls)) {
                    $kurs['error'][] = 'Fehler: Die URL im Feld <i>Bemerkungen</i> ist die Standard-URL des Anbieters; bitte verwenden Sie kurspezifische URLs.';
                } else {
                    $check_maxlen_bemerkungen = $maxlen_bemerkungen + strlen($durchf_urls[0]) + 6 /*do not count the URL and special characters needed for the URL - but the URL text _is_ counted*/
                    ;
                }
            } else {
                $check_maxlen_bemerkungen = $maxlen_bemerkungen;
            }

            if ($check_maxlen_bemerkungen) { /*bei unklaren URL-Verhältnissen wird die Länge nicht geprüft, da sowieso ein Fehler ausgegeben wird - mit dem Hinweis nur max. 1 URL zu verwenden*/
                if (strlen($durchf['bemerkungen']) > $check_maxlen_bemerkungen) {
                    $kurs['error'][] = 'Fehler: Im Feld <i>Bemerkungen</i> sind max. ' . $maxlen_bemerkungen . ' Zeichen erlaubt; URLs werden dabei nicht mitgez&auml;hlt. Eingegebene Zeichen: ' . strlen($durchf['bemerkungen']);
                }
            }

            if (($badWord = $this->tools->containsStopword($durchf['bemerkungen'], $stopwords)) !== false) {
                $kurs['error'][] = 'Fehler: Im Feld <i>Bemerkungen</i> sind Angaben zu <i>' . $badWord . '</i> nicht erlaubt.';
            }
        }

        $kurs_urls = $this->tools->getUrls($kurs['beschreibung']);
        if (sizeof((array)$kurs_urls)) {
            if ($has_durchf_urls) {
                $kurs['error'][] = 'Fehler: URLs k&ouml;nnen nicht gleichzeitig im Feld <i>Kursbeschreibung</i> und im Feld <i>Bemerkungen</i> angegeben werden.';
            }
            if (sizeof((array)$kurs_urls) > 1) {
                $kurs['error'][] = 'Fehler: Im Feld <i>Kursbeschreibung</i> ist nur eine URL erlaubt. Gefundene URLs: ' . implode(', ', $kurs_urls);
            } else if ($this->tools->isAnbieterUrl($kurs_urls)) {
                $kurs['error'][] = 'Fehler: Die URL im Feld <i>Kursbeschreibung</i> ist die Standard-URL des Anbieters; bitte verwenden Sie kurspezifische URLs.';
            }
        }


        // promotion laden
        if ($_POST['promote_mode'] == 'times' || $_POST['promote_mode'] == 'date') {
            if ($_POST['promote_mode'] == 'times') {
                $kurs['promote_mode'] = $_POST['promote_mode'];
                $kurs['promote_param'] = intval($_POST['promote_param_times']);
                $kurs['promote_active'] = $kurs['promote_param'] > 0 ? 1 : 0;
            } else if ($_POST['promote_mode'] == 'date') {
                $kurs['promote_mode'] = $_POST['promote_mode'];
                $kurs['promote_param'] = substr($this->checkDate($_POST['promote_param_date'], $kurs['error']), 0, 10);
                $kurs['promote_active'] = (sizeof((array)$kurs['error']) == 0 && $kurs['promote_param'] > strftime("%Y-%m-%d")) ? 1 : 0;
            }

            // TODEL: Promote AGB
            if (intval($_POST['promote_agb_read']) != 1) {
                $kurs['error'][] = "Fehler: Um einen Kurs zu bewerben, m&uuml;ssen Sie zun&auml;chst die AGB best&auml;tigen.";
            }
            // /TODEL: Promote AGB
        } else {
            $kurs['promote_mode'] = '';
            $kurs['promote_param'] = '';
            $kurs['promote_active'] = 0;
        }

        return $kurs;
    }

    private
    function ist_bagatelle($oldData, $newData)
    {
        /* if($test) {
            echo '<table><tr><td width="50%" valign="top"><pre>';
                print_r($oldData);
            echo '</pre></td><td width="50%" valign="top"><pre>';
                print_r($newData);
            echo '</pre></td></tr></table>';
        } */

        if (!$oldData['rights_editTitel']) {
            $newData['titel'] = $oldData['titel'];
        }
        if (!$oldData['rights_editAbschluss']) {
            // $newData['abschluss'] = $oldData['abschluss'];
            $newData['msgtooperator'] = $oldData['msgtooperator'];
        }

        //    $allowed_kfields = array('error', 'info', 'durchf', 'msgtooperator', 'unterrichtsart', 'useredit_stichwoerter', 'useredit_allstichwoerter', 'useredit_stichwoerterold');
        $allowed_kfields = array('error', 'info', 'durchf', 'msgtooperator');

        // if( !$this->framework->iniRead('useredit.unterrichtsartenspeichern', '') ) // suggestions are not "changes"
        //    array_push($allowed_kfields, 'unterrichtsart'); // needs array compare

        $allowed_dfields = array('id', 'nr', 'stunden', 'teilnehmer', 'preis', 'preishinweise', 'sonderpreis', 'sonderpreistage', 'beginn', 'ende',
            'beginnoptionen', 'zeit_von', 'zeit_bis', 'kurstage', 'tagescode', 'stadtteil', 'strasse', 'dauer');

        // nach Aenderungen im Kurs suchen
        reset($newData);
        foreach ($newData as $name => $newValue) {
            if ($newValue != $oldData[$name]) {
                if (!in_array($name, $allowed_kfields)) {
                    $this->keine_bagatelle_why = "$name";
                    return false;
                }
            }
        }

        $diff_df = "";
        // nach Änderungen in den Durchführungen suchen (Löschen von Df sind Bagatellen)
        for ($n = 0; $n < sizeof((array)$newData['durchf']); $n++) {
            // suche nach einer alten Df, die dieselben Daten wie die Neue hat bzw. nur Änderungen, die erlaubt sind
            $template_found = false;

            for ($o = 0; $o < sizeof((array)$oldData['durchf']); $o++) {
                $o_is_fine = true;
                reset($newData['durchf'][$n]);
                foreach ($newData['durchf'][$n] as $name => $newValue) {
                    if ($newValue != cs8($oldData['durchf'][$o][$name])
                        && !in_array($name, $allowed_dfields)) {
                        $o_is_fine = false;
                        $this->keine_bagatelle_why = "$name";
                        break;
                    }
                }

                if ($o_is_fine) {
                    $template_found = true;
                    break;
                }
            }

            if (!$template_found) {
                return false; // neue Durchführung oder Durchführungsänderungen, die über eine Bagatelle hinausgehen
            }

            // weiter mit der nächsten, neuen/geänderten Durchführung
        }

        return true; // alle Änderungen sind Bagatell-Änderungen
    }

    function saveKursToDb(&$newData)
    {
        // kurs in datenbank speichern
        //
        // $kurs ist ein array wie unter loadKursFromPOST() beschrieben, der Aufruf dieser Funktion kann dabei das
        // Feld $kurs['error'] erweitern; alle anderen Felder werden nur gelesen

        // UTF8-Decoding
        $newData['category'] = (PHP7 ? $newData['category'] : utf8_decode($newData['category']));
        $newData['titel'] = (PHP7 ? $newData['titel'] : utf8_decode($newData['titel']));
        $newData['org_titel'] = (PHP7 ? $newData['org_titel'] : utf8_decode($newData['org_titel']));
        $newData['beschreibung'] = (PHP7 ? $newData['beschreibung'] : utf8_decode($newData['beschreibung']));
        $newData['lernziele'] = (PHP7 ? $newData['lernziele'] : utf8_decode($newData['lernziele']));
        $newData['thema'] = (PHP7 ? $newData['thema'] : utf8_decode($newData['thema']));
        $newData['bu_nummer'] = (PHP7 ? $newData['bu_nummer'] : utf8_decode($newData['bu_nummer']));
        $newData['fu_knr'] = (PHP7 ? $newData['fu_knr'] : utf8_decode($newData['fu_knr']));
        $newData['azwv_knr'] = (PHP7 ? $newData['azwv_knr'] : utf8_decode($newData['azwv_knr']));
        $newData['msgtooperator'] = (PHP7 ? $newData['msgtooperator'] : utf8_decode($newData['msgtooperator']));
        $newData['niveau_stufe'] = (PHP7 ? $newData['niveau_stufe'] : utf8_decode($newData['niveau_stufe']));
        $newData['sprachlevel'] = (PHP7 ? $newData['sprachlevel'] : utf8_decode($newData['sprachlevel']));
        $newData['kompetenzen'] = (PHP7 ? $newData['kompetenzen'] : utf8_decode($newData['kompetenzen']));
        $newData['lernform_stichwort'] = (PHP7 ? $newData['lernform_stichwort'] : utf8_decode($newData['lernform_stichwort']));
        $newData['foerderung_stichwort'] = (PHP7 ? $newData['foerderung_stichwort'] : utf8_decode($newData['foerderung_stichwort']));
        $newData['einstieg'] = (PHP7 ? $newData['einstieg'] : utf8_decode($newData['einstieg']));
        // $newData['unterrichtsart'] = (PHP7 ? $newData['unterrichtsart'] : utf8_decode($newData['unterrichtsart']));
        $newData['promote_mode'] = (PHP7 ? $newData['promote_mode'] : utf8_decode($newData['promote_mode']));
        $newData['promote_param'] = (PHP7 ? $newData['promote_param'] : utf8_decode($newData['promote_param']));
        $newData['promote_active'] = (PHP7 ? $newData['promote_active'] : utf8_decode($newData['promote_active']));
        // $newData['abschluss'] = (PHP7 ? $newData['abschluss'] : utf8_decode($newData['abschluss']));


        /*        $newData['useredit_stichwoerter'] = (PHP7 ? $newData['useredit_stichwoerter'] : utf8_decode($newData['useredit_stichwoerter']));
                $newData['useredit_allstichwoerter'] = (PHP7 ? $newData['useredit_allstichwoerter'] : utf8_decode($newData['useredit_allstichwoerter']));
                $newData['useredit_stichwoerterold'] = (PHP7 ? $newData['useredit_stichwoerterold'] : utf8_decode($newData['useredit_stichwoerterold']));*/


        global $controlTags;
        $db = new DB_Admin;
        $user = $this->getAdminAnbieterUserId20();
        $today = strftime("%Y-%m-%d %H:%M:%S");
        $kursId = $newData['id'];
        $oldData = $this->loadKursFromDb($kursId);
        if (sizeof((array)$oldData['error'])) {
            $newData['error'] = $oldData['error'];
            return;
        }

        $actions = '';
        $protocol = false;

        $logwriter = new LOG_WRITER_CLASS;
        $logwriter->addDataFromTable('kurse', $kursId, 'preparediff');

        // BAGATELLE?
        if ($this->ist_bagatelle($oldData, $newData)) {
            // die Änderung IST eine BAGATELLE
            $logwriter->addData('ist_bagatelle', 1);
            if ($oldData['user_modified'] == $this->getAdminAnbieterUserId20()) {
                // wenn die letzte Änderung eine Onlinepflege war, die potentiell noch nicht von der Redaktion eingesehen wurde,
                // ist auch die neue Änderung keine Bagatelle
            } else {
                $user = $this->getAdminAnbieterUserId19();
            }
        } else {
            // die Änderung ist KEINE BAGATELLE - Nicht-Bagatelländerung erlaubt?
            if ($this->canEditBagatelleOnly()) {
                $newData['error'][] = 'Fehler: Der angemeldete Benutzer hat <b>nicht das Recht</b> diese &Auml;nderungen am Feld <i>' . htmlspecialchars($this->keine_bagatelle_why) . '</i> vorzunehmen.<br />
									   Es d&uuml;rfen nur Datum und Preis und andere Felder in gewissen Grenzen ge&auml;ndert werden.
									   <a href="' . $this->framework->getHelpUrl($this->framework->iniRead('useredit.help.norights', '20')) . '" target="_blank" rel="noopener noreferrer">Weitere Informationen hierzu ...</a><br />';
                // $db->close();
                return;
            }
        }

        // CREATE A NEW RECORD?
        if ($kursId == 0) {
            $anbieter = intval($_SESSION['loggedInAnbieterId']);
            $db->query("SELECT user_grp, user_access FROM anbieter WHERE id=$anbieter AND freigeschaltet = 1;");
            $db->next_record();
            $user_grp = intval($db->fcs8('user_grp'));
            $user_access = intval($db->fcs8('user_access'));
            $db->query("INSERT INTO kurse  (user_created, date_created, user_modified, date_modified, user_grp,  user_access,  anbieter,  freigeschaltet, titel, titel_sorted, beschreibung, lernziele, msgtooperator)
									VALUES ($user, 		  '$today',     $user,         '$today',      $user_grp, $user_access, $anbieter, 0, '" . addslashes($newData['titel']) . "', '" . addslashes(g_eql_normalize_natsort($newData['titel'])) . "', '" . addslashes($newData['beschreibung']) . "','" . addslashes($newData['lernziele']) . "','" . addslashes($newData['msgtooperator']) . "')
									;");
            $kursId = $db->insert_id();
            $newData['id'] = $kursId;
        }

        // fuer Stichwort rollstuhlgerecht erst $rollstuhlgerecht = 0
        $rollstuhlgerecht = 0;

        // DURCHFÜHRUNGS-Änderungen ablegen
        for ($d = 0; $d < sizeof((array)$newData['durchf']); $d++) {
            // neue daten holen
            $newDurchf = $newData['durchf'][$d];

            // passende alten daten suchen, wenn es keine gibt, ist dies eine neue Durchführung!
            $isNew = false;
            $oldDurchf = array();
            if ($newDurchf['id']) {
                // existierende durchführung
                for ($d2 = 0; $d2 < sizeof((array)$oldData['durchf']); $d2++) {
                    if ($oldData['durchf'][$d2]['id'] == $newDurchf['id']) {
                        $oldDurchf = $oldData['durchf'][$d2];
                        $oldData['durchf'][$d2]['id'] = 0; // mark as used
                        break;
                    }
                }
                if (sizeof((array)$oldDurchf) == 0) {
                    $newData['error'][] = "Fataler Fehler: Die Durchf&uuml;hrung ID " . $newDurchf['id'] . " kann nicht gefunden werden!";
                    return;
                }
            } else {
                // neue Durchführung!
                $db->query("SELECT user_grp, user_access FROM kurse WHERE id=$kursId;");
                $db->next_record();
                $user_grp = intval($db->fcs8('user_grp'));
                $user_access = intval($db->fcs8('user_access'));

                $db->query("INSERT INTO durchfuehrung (user_created, date_created, user_modified, date_modified, user_grp, user_access, bemerkungen) VALUES ($user, '$today', $user, '$today', $user_grp, $user_access, '')");
                $newDurchf['id'] = $db->insert_id();
                $oldDurchf['id'] = $newDurchf['id'];        // damit diese unten nicht aktualisiert werden muss ...
                $newData['durchf'][$d]['id'] = $newDurchf['id'];    // damit die neue ID beim caller ankommt

                $db->query("SELECT MAX(structure_pos) AS temp FROM kurse_durchfuehrung WHERE primary_id=$kursId");
                $db->next_record();

                $db->query("INSERT INTO kurse_durchfuehrung (primary_id, secondary_id, structure_pos) VALUES ($kursId, " . $newDurchf['id'] . ", " . ($db->fcs8('temp') + 1) . ")");

                $isNew = true;

                $actions .= ' DURCHF-INSERT ';
            }

            // änderungen überprüfen
            $sqlExpr = '';
            reset($newDurchf);
            foreach ($newDurchf as $name => $value) {
                // falls eine DF rollstuhlgerecht -> $rollstuhlgerecht = 1
                if ($name == "rollstuhlgerecht" && $value == "1") {
                    $rollstuhlgerecht = 1;
                }

                $value = (PHP7 ? $value : utf8_decode($value));
                if (strval($value) != strval($oldDurchf[$name]) || !isset($oldDurchf[$name])) {
                    // sql
                    $sqlExpr .= ", $name='" . addslashes($value) . "'";
                    // $sqlExpr .= ", $name='" . ($value !== null ? addslashes($value) : '') . "'";

                    // protocol
                    if (!$isNew) {
                        $oldVal = $oldDurchf[$name];
                        $newVal = (PHP7 ? $newDurchf[$name] : utf8_decode($newDurchf[$name]));

                        $protocol = true;
                    }
                }
            }

            // aenderungen schreiben
            if ($sqlExpr != '') {
                //$sqlExpr = "UPDATE durchfuehrung SET user_modified={$user}, date_modified='{$today}'{$sqlExpr} WHERE id=" . $newDurchf['id'] . ";";
                $sqlExpr = "UPDATE durchfuehrung SET user_modified=" . $user . ", date_modified='" . $today . "'" . $sqlExpr . " WHERE id=" . $newDurchf['id'] . ";";
                $db->query($sqlExpr);

                // protocol
                if ($isNew) {
                    $protocol = true;
                }
                $actions .= ' DURCHF-UPDATE ';
            }
        }

        $rollstuhlgerecht_stichwort = $this->testStichwort($kursId, $controlTags['rollstuhlgerecht']);
        // wenn eine DF rollstuhlgerecht und Stichwort nicht gesetzt - dann Stichwort schreiben
        if ($rollstuhlgerecht == 1 && $rollstuhlgerecht_stichwort == 0) {
            $this->saveStichwort($kursId, 0, $controlTags['rollstuhlgerecht']);
        }
        // wenn keine DF rollstuhlgerecht und Stichwort gesetzt - dann Stichwort loeschen
        if ($rollstuhlgerecht != 1 && $rollstuhlgerecht_stichwort > 0) {
            $this->saveStichwort($kursId, $controlTags['rollstuhlgerecht'], 0);
        }


        // ÜBERSCHÜSSIGE durchführungen löschen
        $delCnt = 0;
        for ($d2 = 0; $d2 < sizeof((array)$oldData['durchf']); $d2++) {
            if ($oldData['durchf'][$d2]['id']) {
                $toDel = $oldData['durchf'][$d2]['id'];
                $db->query("DELETE FROM kurse_durchfuehrung WHERE primary_id=$kursId AND secondary_id=$toDel");
                $db->query("DELETE FROM durchfuehrung WHERE id=$toDel");
                $delCnt++;

                $actions .= ' DURCHF-DELETE ';
            }
        }

        if ($delCnt) {
            // protocol
            $protocol = true;
        }

        // KURS-Änderungen ablegen
        if (!$oldData['rights_editTitel']) {
            $newData['titel'] = $oldData['titel'];
        }
        /*        if (!$oldData['rights_editAbschluss']) {
                    //   $newData['abschluss'] = $oldData['abschluss'];
                    $newData['msgtooperator'] = $oldData['msgtooperator'];
                }*/

        if ($actions != ''
            || $oldData['category'] != $newData['category']
            || $oldData['titel'] != $newData['titel']
            || $oldData['beschreibung'] != $newData['beschreibung']
            || $oldData['lernziele'] != $newData['lernziele']
            || $oldData['thema'] != $newData['thema']
            || $oldData['bu_nummer'] != $newData['bu_nummer']
            || $oldData['fu_knr'] != $newData['fu_knr']
            || $oldData['azwv_knr'] != $newData['azwv_knr']
            || $oldData['msgtooperator'] != $newData['msgtooperator']
            || $oldData['niveau_stufe'] != $newData['niveau_stufe']
            || $oldData['sprachlevel'] != $newData['sprachlevel']
            || $oldData['kompetenzen'] != $newData['kompetenzen']
            || $oldData['lernform_stichwort'] != $newData['lernform_stichwort']
            || $oldData['foerderung_stichwort'] != $newData['foerderung_stichwort']
            || $oldData['einstieg'] != $newData['einstieg']

        ) {
            // protocol
            if ($oldData['beschreibung'] != $newData['beschreibung']) {
                $protocol = true;
            }

            if ($oldData['lernziele'] != $newData['lernziele']) {
                $protocol = true;
            }

            $fields = array('titel', 'bu_nummer', 'fu_knr', 'azwv_knrd', 'msgtooperator');
            foreach ($fields as $key => $value) {
                if ($oldData[$value] != $newData[$value]) {
                    $protocol = true;
                }
            }


            // update record
            $sql = "UPDATE kurse SET titel='" . addslashes($newData['titel']) . "',
                                     titel_sorted='" . addslashes(g_eql_normalize_natsort($newData['titel'])) . "',
									 beschreibung='" . addslashes($newData['beschreibung']) . "',
									 lernziele='" . addslashes($newData['lernziele']) . "',
									 thema='" . addslashes($newData['thema']) . "',
									 bu_nummer='" . addslashes($newData['bu_nummer']) . "',
									 fu_knr='" . addslashes($newData['fu_knr']) . "',
									 azwv_knr='" . addslashes($newData['azwv_knr']) . "',
									 msgtooperator='" . addslashes($newData['msgtooperator']) . "',";

            if ($this->framework->iniRead('onlinepflege.invorbereitung', "") == 1)
                $sql .= "freigeschaltet=0, ";

            if ($protocol != '') {
                $sql .= " user_modified={$user}, ";    // der Benutzer, wird nur geaendert, wenn etwas im Protokoll steht; dies ist notwendig, da durch die Suche nach dem Benutzer (20) die Redaktion die Aenderungen im Protokoll ueberprueft
            }                                                    // das Datum muss dagegen auch geaendert werden, wenn nur bei der Promotion etwas geaendert wurde, da ansonsten die Aenderungen nicht "live" geschaltet werden (bzw. nur stark verzoegert)
            $sql .= " date_modified='{$today}' WHERE id=$kursId;";

            echo $sql;
            $db->query($sql);

            if ($newData['category'] != '' || $oldData['category'] != '') {
                $this->saveStichwort($kursId, $oldData['category'], $newData['category']);
            }

            $this->saveStichwort($kursId, $oldData['lernform_stichwort'], $newData['lernform_stichwort']);

            foreach ($newData['niveau_stufe'] as $niveaustufe) {
                if (!in_array($niveaustufe, $oldData['niveau_stufe'])) {
                    $this->saveStichwort($kursId, 0, $niveaustufe);
                }
            }
            foreach ($oldData['niveau_stufe'] as $niveaustufe) {
                if (!in_array($niveaustufe, $newData['niveau_stufe'])) {
                    $this->saveStichwort($kursId, $niveaustufe, 0);
                }
            }
            foreach ($newData['sprachlevel'] as $sprachlevel) {
                if (!in_array($sprachlevel, $oldData['sprachlevel'])) {
                    $this->saveStichwort($kursId, 0, $sprachlevel);
                }
            }
            foreach ($oldData['sprachlevel'] as $sprachlevel) {
                if (!in_array($sprachlevel, $newData['sprachlevel'])) {
                    $this->saveStichwort($kursId, $sprachlevel, 0);
                }
            }
            $this->saveESCOstichwort($kursId, $oldData['kompetenzen'], $newData['kompetenzen']);

            foreach ($newData['foerderung_stichwort'] as $allStichwort) {
                if (!in_array($allStichwort, $oldData['foerderung_stichwort'])) {
                    $this->saveStichwort($kursId, 0, $allStichwort);
                }
            }
            foreach ($oldData['foerderung_stichwort'] as $allStichwort) {
                if (!in_array($allStichwort, $newData['foerderung_stichwort'])) {
                    $this->saveStichwort($kursId, $allStichwort, 0);
                }
            }

            $einstieg_stichwort = $this->testStichwort($kursId, $controlTags['Einstieg bis Kursende moeglich']);
            if ($newData['einstieg'] && $einstieg_stichwort == 0) {
                $this->saveStichwort($kursId, 0, $controlTags['Einstieg bis Kursende moeglich']);
            }

            if ($newData['einstieg'] != 1 && $einstieg_stichwort > 0) {
                $this->saveStichwort($kursId, $controlTags['Einstieg bis Kursende moeglich'], 0);
            }

            // trigger - dies berechnet u.a. die neue Vollstaendigkeit
            update_kurs_state($kursId, array('from_cms' => 0, 'set_plz_stadtteil' => 2, 'write' => 1));

            // log after the record being written
            $logwriter->addDataFromTable('kurse', $kursId, 'creatediff');
            $logwriter->log('kurse', $kursId, $user, 'edit');

            // done.
            $actions .= ' KURS-UPDATE ';
        }

        // echo $actions;
        // $db->close();
    }

    function deleteKurs($kursId)
    {
        // kurs als gelöscht markieren ...
        $user = $this->getAdminAnbieterUserId20();
        $today = strftime("%Y-%m-%d %H:%M:%S");

        // alten Wert fuer "freigeschaltet" holen
        $db = new DB_Admin;
        $db->query("SELECT freigeschaltet FROM kurse WHERE id=$kursId;");
        if ($db->next_record()) {
            $oldFreigeschaltet = $db->fcs8('freigeschaltet');

            // neuen Wert fuer "freigeschaltet" setzen
            $db->query("UPDATE kurse SET freigeschaltet=2, user_modified={$user}, date_modified='{$today}' WHERE id=$kursId;");

            // ab ins Protokoll
            $logwriter = new LOG_WRITER_CLASS;
            $logwriter->addData('freigeschaltet', array($oldFreigeschaltet, 2));
            $logwriter->log('kurse', $kursId, $user, 'edit');
        }
        // $db->close();
    }

    function renderEditorToolbar($addKursUrl)
    {
        $ret = '<small>';
        $ret .= '<a href="" onclick="add_chars($(this), \'\\\'\\\'\\\'\', \'\\\'\\\'\\\'\'); return false;" style="font-weight:bold; letter-spacing: 1px; text-decoration: none; font-size: 20px; color: black;" title="Markieren Sie den zu fettenden Text und klicken Sie dann diese Schaltfl&auml;che" >B</a> &nbsp; ';
        $ret .= '<a href="" onclick="add_chars($(this), \'\\\'\\\'\', \'\\\'\\\'\'); return false;" style="font-style:italic; letter-spacing: 1px;text-decoration: none; font-size: 20px; color: black;" title="Markieren Sie den kursiv darzustellenden Text und klicken Sie dann diese Schaltfl&auml;che" >I</a> &nbsp; ';

        if ($addKursUrl) {
            $ret .= '<a href="" onclick="add_chars($(this), \'[[http://verweis.com | Kurs-URL\', \']]\'); return false;" style="letter-spacing: 1px;" title="Markieren Sie den Text, den Sie als Verweis verwenden m&ouml;chten, und klicken Sie dann diese Schaltfl&auml;che">[[Verweis]]</a> &nbsp; ';
        }
        $ret .= '</small><br />';
        return $ret;
        // must be followed by the textarea element! if you change the hierarchy, please also change "parent().parent()" in add_chars() in jquery.wisy.js - see (**)!
    }


    function andereNiveaustufen()
    {
        echo '<div class="niv-header"><p>Der von Ihnen angelegte Kurs, besitz wegen der Kurskategorie keine Niveaustufe.</p></div>';
    }

//Erzeugt die Niveaustufen und deren Inhalte.
    function createNiveaustufe($checkboxValue = [])
    {
        $useredit_niveaustufenfrage = $this->framework->iniRead('useredit.niveaustufenfrage', '');
        $useredit_niveauA = $this->framework->iniRead('useredit.niveauA', '');
        $useredit_niveauB = $this->framework->iniRead('useredit.niveauB', '');
        $useredit_niveauC = $this->framework->iniRead('useredit.niveauC', '');
        $useredit_recommendationtxt = $this->framework->iniRead('useredit.recommandationtxt', '');


        $sql_category = array();
        $db = new DB_Admin();
        $sql = "SELECT id, stichwort FROM stichwoerter WHERE stichwort IN('Niveau A', 'Niveau B', 'Niveau C') ORDER BY id;";
        $db->query($sql);

        while ($db->next_record()) {
            $id = $db->fcs8('id');
            $stichwort = $db->fcs8('stichwort');
            if ($stichwort != '')
                if ($stichwort != '') {
                    // Hier überprüfen, welchem Niveau das Stichwort entspricht und die ID speichern
                    switch ($stichwort) {
                        case 'Niveau A':
                            $sql_category['Niveau A'] = $id;
                            break;
                        case 'Niveau B':
                            $sql_category['Niveau B'] = $id;
                            break;
                        case 'Niveau C':
                            $sql_category['Niveau C'] = $id;
                            break;
                    }
                }
        }
        $idNiveauA = $sql_category['Niveau A'];
        $idNiveauB = $sql_category['Niveau B'];
        $idNiveauC = $sql_category['Niveau C'];


        echo '<div class="niv-header"><h3>';
        if ($useredit_niveaustufenfrage) echo $useredit_niveaustufenfrage;
        else echo 'Welches Kompetenzniveau erlangen die Teilnehmenden nach Abschluss Ihres Kurses?';
        echo '</h3><span class="wisy-ki-niveaustufe"></span></div>';
        echo '<div class="niv-content-area">';

        if (count($checkboxValue) > 0) {
            $niveaustufeA = in_array('Niveau A', $checkboxValue) ? 'checked' : '';
            $niveaustufeB = in_array('Niveau B', $checkboxValue) ? 'checked' : '';
            $niveaustufeC = in_array('Niveau C', $checkboxValue) ? 'checked' : '';
        }


        //Niveau A - Grundstufe
        echo '<div class="niveauA wisy-niveaustufen">';
        if ($useredit_niveauA) echo '<div class="niv-titel-header nivA-titel"><span class="nivA-titel niv-titel">' . $useredit_niveauA . '</span>';
        else echo '<div class="niv-titel-header nivA-titel"><span class="nivA-titel niv-titel">Grundstufe</span>';
        echo '<div class="niv-info niv-infoA"><p class="niv-info-help">?</p></div></div>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox-niveauA" class="wisy-niveaustufen-check" name="niveau_stufe[]" value="' . $idNiveauA . '" ' . $niveaustufeA . '><div class="slider round"></div></label></div>';
        if ($useredit_recommendationtxt) echo '<p class="niv-text">' . $useredit_recommendationtxt . '</p>';
        else echo '<p class="niv-text with-icon">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        //Niveau B - Aufbaustufe
        echo '<div class="niveauB wisy-niveaustufen">';
        if ($useredit_niveauB) echo '<div class="niv-titel-header nivB-titel"><span class="nivB-titel niv-titel">' . $useredit_niveauB . '</span>';
        else echo '<div class="niv-titel-header nivB-titel"><span class="nivB-titel niv-titel">Fortgeschrittenenstufe</span>';
        echo '<div class="niv-info niv-infoB"><p class="niv-info-help">?</p></div></div>';
        //echo '<p class="niv-txt niv-text-b"> Die Teilnehmenden erlangen Kompetenzen zur &uuml;berwiegend selbstst&auml;ndigen Umsetzung erweiterter Aufgaben in einem sich teilweise ver&auml;ndernden Bereich.</p>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox-niveauB"class="wisy-niveaustufen-check" name="niveau_stufe[]" value="' . $idNiveauB . '" ' . $niveaustufeB . '><div class="slider round"></div></label></div>';
        if ($useredit_recommendationtxt) echo '<p class="niv-text">' . $useredit_recommendationtxt . '</p>';
        else echo '<p class="niv-text with-icon">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        //Niveau C - Fortgeschrittenenstufe
        echo '<div class="niveauC wisy-niveaustufen">';
        if ($useredit_niveauC) echo '<div class="niv-titel-header nivC-titel"><span class="nivC-titel niv-titel">' . $useredit_niveauC . '</span>';
        else echo '<div class="niv-titel-header nivC-titel"><span class="nivC-titel niv-titel">Expert*innenstufe</span>';
        echo '<div class="niv-info niv-infoC"><p class="niv-info-help">?</p></div></div>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox-niveauC" class="wisy-niveaustufen-check" name="niveau_stufe[]" value="' . $idNiveauC . '"' . $niveaustufeC . '><div class="slider round"></div></label></div>';
        if ($useredit_recommendationtxt) echo '<p class="niv-text">' . $useredit_recommendationtxt . '</p>';
        else echo '<p class="niv-text with-icon">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        echo '</div>';

        echo '</div>';

        $this->niveauInfo();
    }

//Beinhaltet die Texte für die Modale.
    function niveauInfo()
    {
        $useredit_niveauA = $this->framework->iniRead('useredit.niveauA', '');
        $useredit_niveauB = $this->framework->iniRead('useredit.niveauB', '');
        $useredit_niveauC = $this->framework->iniRead('useredit.niveauC', '');

        $useredit_niveauA_infoW = $this->framework->iniRead('useredit.niveauA.infoW', '');
        $useredit_niveauB_infoW = $this->framework->iniRead('useredit.niveauB.infoW', '');
        $useredit_niveauC_infoW = $this->framework->iniRead('useredit.niveauC.infoW', '');

        $useredit_niveauA_infoF = $this->framework->iniRead('useredit.niveauA.infoF', '');
        $useredit_niveauB_infoF = $this->framework->iniRead('useredit.niveauA.infoF', '');
        $useredit_niveauC_infoF = $this->framework->iniRead('useredit.niveauA.infoF', '');

        $useredit_niveauA_infoP = $this->framework->iniRead('useredit.niveauA.infoP', '');
        $useredit_niveauB_infoP = $this->framework->iniRead('useredit.niveauA.infoP', '');
        $useredit_niveauC_infoP = $this->framework->iniRead('useredit.niveauA.infoP', '');

        echo '<div class="niveauInfo niveauInfo-a">';
        echo '<div class="niveauInfo-content"><span class="niveauInfo-close">&times;</span>';
        if ($useredit_niveauA) echo '<h1>' . $useredit_niveauA . '</h1><br><h3>Wissen</h3>';
        else echo '<h1>Grundstufe</h1><br><h3>Wissen</h3>';
        if ($useredit_niveauA_infoW) echo '<p class="nivA-info-f-wissen">' . $useredit_niveauA_infoW . '</p>';
        else echo '<p class="nivA-info-f-wissen">&Uuml;ber allgemeines, grundlegendes Wissen in einem Lern- oder Arbeitsbereich verf&uuml;gen, um Inhalte wiederzugeben.</p>';
        echo '<br><h3>Fertigkeiten</h3>';
        if ($useredit_niveauA_infoF) echo '<p class="nivA-info-f-fertigkeiten">' . $useredit_niveauA_infoF . '</p>';
        else echo '<p class="nivA-info-f-fertigkeiten">&Uuml;ber kognitive und praktische F&auml;higkeiten verf&uuml;gen, zur Ausf&uuml;hrung einfacher Aufgaben nach vorgegebenen Ma&szlig;st&auml;ben wiedergeben, beurteilen und elementare Zusammenh&auml;nge herstellen.</p>';
        echo '<br><h3>Personale Kompetenz</h3>';
        if ($useredit_niveauA_infoP) echo '<p class="nivA-info-f-kompetenz">' . $useredit_niveauA_infoP . '</p>';
        else echo '<p class="nivA-info-f-kompetenz">Mit anderen in einer Gruppe zusammen lernen, diese wahrnehmen. Sich beteiligen, Kritik aufnehmen und &auml;u&szlig;ern. Das eigene Handeln und das anderer einsch&auml;tzen. Lernberatung oder Lernhilfen nutzen.</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="niveauInfo niveauInfo-b">';
        echo '<div class="niveauInfo-content"><span class="niveauInfo-close">&times;</span>';
        if ($useredit_niveauB) echo '<h1>' . $useredit_niveauB . '</h1><br><h3>Wissen</h3>';
        else echo '<h1>Fortgeschrittenenstufe</h1><br><h3>Wissen</h3>';
        if ($useredit_niveauB_infoW) echo '<p class="nivB-info-f-wissen">' . $useredit_niveauB_infoW . '</p>';
        else echo '<p class="nivB-info-f-wissen">&Uuml;ber erweitertes bzw. vertieftes allgemeines Wissen oder &uuml;ber erweitertes Fachwissen in einem Lern- oder Arbeitsbereich verf&uuml;gen und dieses anwenden.</p>';
        echo '<br><h3>Fertigkeiten</h3>';
        if ($useredit_niveauB_infoF) echo '<p class="nivB-info-f-fertigkeiten">' . $useredit_niveauB_infoF . '</p>';
        else echo '<p class="nivB-info-f-fertigkeiten">&Uuml;ber kognitive und praktische Fertigkeiten zur Ausf&uuml;hrung bzw. zur Planung und Bearbeitung von Aufgaben in einem Lern- oder Arbeitsbereich verf&uuml;gen und diese begr&uuml;ndet ausf&uuml;hren.</p>';
        echo '<br><h3>Personale Kompetenz</h3>';
        if ($useredit_niveauB_infoP) echo '<p class="nivB-info-f-kompetenz">' . $useredit_niveauB_infoP . '</p>';
        else echo '<p class="nivB-info-f-kompetenz">In einer Gruppe mitwirken bzw. mitgestalten und Unterst&uuml;tzung anbieten. Werte annehmen, eigenst&auml;ndig und verantwortungsbewusst agieren.</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="niveauInfo niveauInfo-c">';
        echo '<div class="niveauInfo-content"><span class="niveauInfo-close">&times;</span>';
        if ($useredit_niveauC) echo '<h1>' . $useredit_niveauC . '</h1><br><h3>Wissen</h3>';
        else echo '<h1>Expert*innenstufe</h1><br><h3>Wissen</h3>';
        if ($useredit_niveauC_infoW) echo '<p class="nivC-info-f-wissen">' . $useredit_niveauC_infoW . '</p>';
        else echo '<p class="nivC-info-f-wissen">&Uuml;ber integriertes bzw. vertieftes fachtheoretisches Wissen in einem Lern- oder Arbeitsbereich zu verf&uuml;gen</p>';
        echo '<br><h3>Fertigkeiten</h3>';
        if ($useredit_niveauC_infoF) echo '<p class="nivC-info-f-fertigkeiten">' . $useredit_niveauC_infoF . '</p>';
        else echo '<p class="nivC-info-f-fertigkeiten">&Uuml;ber ein sehr breites Spektrum spezialisierter kognitiver und praktikscher Fertigkeiten verf&uuml;gen, um zu analysieren, gegen&uuml;berzustellen und umfassende Transferleistungen zu erbringen.</p>';
        echo '<br><h3>Personale Kompetenz</h3>';
        if ($useredit_niveauC_infoP) echo '<p class="nivC-info-f-kompetenz">' . $useredit_niveauC_infoP . '</p>';
        else echo '<p class="nivC-info-f-kompetenz">Arbeitsprozess kooperativ planen und gestalten, andere anleiten und mit fundierter Lernberatung unterst&uuml;tzen. Auch fach&uuml;bergreifend komplexe Sachverhalte strukturiert, zielgerichtet und adressatenbezogen.</p>';
        echo '</div>';
        echo '</div>';

    }

    function sprachniveaus($checkboxValue = [])
    {
        $sql_category = array();
        $db = new DB_Admin();
        $sql = "SELECT id, stichwort FROM stichwoerter WHERE stichwort IN('A1', 'A2', 'B1', 'B2', 'C1', 'C2') ORDER BY id;";
        $db->query($sql);

        while ($db->next_record()) {
            $id = $db->fcs8('id');
            $stichwort = $db->fcs8('stichwort');
            if ($stichwort != '')
                if ($stichwort != '') {
                    // Hier überprüfen, welchem Niveau das Stichwort entspricht und die ID speichern
                    switch ($stichwort) {
                        case 'A1':
                            $sql_category['A1'] = $id;
                            break;
                        case 'A2':
                            $sql_category['A2'] = $id;
                            break;
                        case 'B1':
                            $sql_category['B1'] = $id;
                            break;
                        case 'B2':
                            $sql_category['B2'] = $id;
                            break;
                        case 'C1':
                            $sql_category['C1'] = $id;
                            break;
                        case 'C2':
                            $sql_category['C2'] = $id;
                            break;
                    }
                }
        }
        $idNiveauA1 = $sql_category['A1'];
        $idNiveauA2 = $sql_category['A2'];
        $idNiveauB1 = $sql_category['B1'];
        $idNiveauB2 = $sql_category['B2'];
        $idNiveauC1 = $sql_category['C1'];
        $idNiveauC2 = $sql_category['C2'];

        if (count($checkboxValue) > 0) {
            $niveaustufeA1 = in_array('A1', $checkboxValue) ? 'checked' : '';
            $niveaustufeA2 = in_array('A2', $checkboxValue) ? 'checked' : '';
            $niveaustufeB1 = in_array('B1', $checkboxValue) ? 'checked' : '';
            $niveaustufeB2 = in_array('B2', $checkboxValue) ? 'checked' : '';
            $niveaustufeC1 = in_array('C1', $checkboxValue) ? 'checked' : '';
            $niveaustufeC2 = in_array('C2', $checkboxValue) ? 'checked' : '';
        }

        // echo '<div class="niveau-menu2" style="height: 350px;">';
        echo '<div class="niv-header"><p> Welches Sprachniveau hat der Kurs?</p></div>';
//Sprachen
        echo '<div class="niv-content">';
        echo '<div class="sprachea1 wisy-sprachenstufen">';
        echo '<div class="niv-titel-header sprachena-titel"><span class="sprache-a-titel sprach-titel niv-titel">A1</span>';
        echo '</div>';
        //echo '<p class="niv-txt niv-text-a">Die Teilnehmenden k&ouml;nnen ganz einfache S&auml;tze verstehen und verwenden.</p>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox" class="wisy-sprachstufen-check" name="sprachlevel[]" value="' . $idNiveauA1 . '" ' . $niveaustufeA1 . '><div class="slider round"></div></label></div>';
        // echo '<p class="niv-text">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        echo '<div class="sprachea2 wisy-sprachenstufen">';
        echo '<div class="niv-titel-header sprachena-titel"><span class="sprache-a-titel sprach-titel niv-titel">A2</span>';
        echo '</div>';
        // echo '<p class="niv-txt niv-text-a">Die Teilnehmenden k&ouml;nnen elementare S&auml;tze und h&auml;ufig gebrauchte Ausdr&uuml;cke verstehen und verwenden.</p>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox" class="wisy-sprachstufen-check" name="sprachlevel[]" value="' . $idNiveauA2 . '"' . $niveaustufeA2 . '><div class="slider round"></div></label></div>';
        //  echo '<p class="niv-text">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        echo '<div class="spracheb1 wisy-sprachenstufen">';
        echo '<div class="niv-titel-header sprachenb-titel"><span class="sprache-b-titel sprach-titel niv-titel">B1</span>';
        echo '</div>';
        //  echo '<p class="niv-txt niv-text-a">Die Teilnehmenden k&ouml;nnen klare Standardsprache verstehen und verwenden.</p>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox" class="wisy-sprachstufen-check" name="sprachlevel[]" value="' . $idNiveauB1 . '"' . $niveaustufeB1 . '><div class="slider round"></div></label></div>';
        //  echo '<p class="niv-text">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        echo '<div class="spracheb2 wisy-sprachenstufen">';
        echo '<div class="niv-titel-header sprachenb-titel"><span class="sprache-b-titel sprach-titel niv-titel">B2</span>';
        echo '</div>';
        //  echo '<p class="niv-txt niv-text-a">Die Teilnehmenden k&ouml;nnen die Sprache selbstst&auml;ndig in einem breiten Themensprektrum verwenden.</p>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox" class="wisy-sprachstufen-check" name="sprachlevel[]" value="' . $idNiveauB2 . '"' . $niveaustufeB2 . '><div class="slider round"></div></label></div>';
        //   echo '<p class="niv-text">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        echo '<div class="sprachec1 wisy-sprachenstufen">';
        echo '<div class="niv-titel-header sprachenc-titel"><span class="sprache-c-titel sprach-titel niv-titel">C1</span>';
        echo '</div>';
        // echo '<p class="niv-txt niv-text-a">Die Teilnehmenden k&ouml;nnen anspruchvolle Texte verstehen und spontan, flie&szlig;end, strukturiert kommunizieren.</p>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox" class="wisy-sprachstufen-check" name="sprachlevel[]" value="' . $idNiveauC1 . '"' . $niveaustufeC1 . '><div class="slider round"></div></label></div>';
        //   echo '<p class="niv-text">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        echo '<div class="sprachec2 wisy-sprachenstufen">';
        echo '<div class="niv-titel-header sprachenc-titel"><span class="sprache-c-titel sprach-titel">C2</span>';
        echo '</div>';
        //  echo '<p class="niv-txt niv-text-a">Die Teilnehmenden k&ouml;nnen alles m&uuml;helos verstehen und fließend kommunizieren.</p>';
        echo '<div class="niv-slider-menu"><p>Stufe ausw&auml;hlen</p><label class="switch"><input type="checkbox" id="checkbox" class="wisy-sprachstufen-check" name="sprachlevel[]" value="' . $idNiveauC2 . '"' . $niveaustufeC2 . '><div class="slider round"></div></label></div>';
        //    echo '<p class="niv-text">Anhand Ihrer Kursbeschreibung empfehlen wir Ihnen diese Stufe.</p>';
        echo '</div>';

        echo '</div>'; //ende von niv-content

        //   echo '</div>'; //ende von Niveau-menu

    }

    function renderVollstMsg($id, $always)
    {
        $msg = '';
        $db = new DB_Admin;
        $temp = update_kurs_state($id, array('write' => 0));
        if ($temp['vmsg'] != '') {
            $vollst = $this->framework->getVollstaendigkeitMsg($db, $id, 'quality.edit');
            $msg .= '<b>Informationen zu Vollst&auml;ndigkeit:</b> ' . $vollst['msg'];
            $msg .= cs8($temp['vmsg']);
            $msg .= '<br> <li>Die mit Stern gekennzeichneten Felder sind Pflichtfelder</li>';
        } else if ($always) {
            $vollst = $this->framework->getVollstaendigkeitMsg($db, $id, 'quality.edit');
            $msg .= $vollst['msg'];
        }

        // $db->close();
        return $msg;
    }

    function renderhoverInfo($infotext)
    {
        echo '<div class="info">';
        echo '<div class="info-icon">';
        echo '<i class="fas fa-info">?</i>';
        echo '</div>';
        echo '<span class="info-text">' . $infotext . '</span>';
        echo '</div>';
    }


    function renderEditKurs($kursId__ /* may be "0" for "new kurs" */)
    {

        global $controlTags;
        // check rights, check, if the kursId belongs to the anbieter logged in
        $db = new DB_Admin;
        $topnotes = array();
        $useredit_stichwoerter = $this->framework->iniRead('useredit.stichwoerter', '');
        if ($useredit_stichwoerter) {
            $useredit_stichwoerterArray = explode(",", $useredit_stichwoerter);
        } else {
            $useredit_stichwoerterArray = array();
        }
        if (is_int($controlTags['Mit Kinderbetreuung'])) {
            array_push($useredit_stichwoerterArray, $controlTags['Mit Kinderbetreuung']);
        }
        $showForm = true;

        switch ($this->isEditable($kursId__)) {
            case 'loginneeded':
                $this->renderLoginScreen();
                return;
            case 'no':
                $topnotes[] = "Der Kurs kann nicht bearbeitet werden.";
                $topnotes[] = "Der Kurs ist nicht oder nicht mehr vorhanden oder der Kurs ist gesperrt.";
                $showForm = false;
                break;
        }

        // see what to do ...
        if (intval($_GET['deletekurs']) == 1) {
            // ... "Delete" hit - maybe this is a subsequent call, but not necessarily
            $this->deleteKurs($kursId__);
            header('Location: ' . $this->bwd . (strpos($this->bwd, '?') === false ? '?' : '&') . 'deleted=' . date("Y-m-d-H-i-s"));
            exit();
        } else if ($_POST['subseq'] == 1 && isset($_POST['cancel'])) {
            // ... a subsequent call: "Cancel" hit
            header('Location: ' . $this->bwd);
            exit();
        } else if ($_POST['subseq'] == 1) {
            // ... a subsequent call: "OK" hit
            $kurs = $this->loadKursFromPOST($kursId__);
            if (sizeof((array)$kurs['error']) == 0) {
                $this->saveKursToDb($kurs);
            } /* no else: saveKursToDb() may also add errors */

            if (sizeof((array)$kurs['error']) > 0) {
                $kurs['error'][] = 'Der Kurs wurde aufgrund der angegebenen Fehler <b>nicht gespeichert.</b>';
            }

            if (sizeof((array)$kurs['error'])) {
                $topnotes = $kurs['error'];
            } else {
                $msg = 'Der Kurs <a href="' . $this->framework->getUrl('k', array('id' => $kurs['id'])) . '">' . htmlspecialchars($kurs['titel']) . '</a> wurde <b>erfolgreich gespeichert.</b>';
                $temp = $this->renderVollstMsg($kurs['id'], false);
                $msg .= ($temp ? '<br /><br />' : '') . $temp;

                setcookie('editmsg', $msg);
                header('Location: ' . $this->framework->getUrl('k', array('id' => $kurs['id'])));
                // $db->close();
                exit();
            }
        } else {
            // the first call
            $kurs = $this->loadKursFromDb($kursId__);

            // UTF8-Encoding after loading from DB
            $kurs['category'] = cs8($kurs['category']);
            $kurs['titel'] = cs8($kurs['titel']);
            $kurs['org_titel'] = cs8($kurs['org_titel']);
            $kurs['beschreibung'] = cs8($kurs['beschreibung']);
            $kurs['lernziele'] = cs8($kurs['lernziele']);
            $kurs['thema'] = cs8($kurs['thema']);
            $kurs['bu_nummer'] = cs8($kurs['bu_nummer']);
            $kurs['fu_knr'] = cs8($kurs['fu_knr']);
            $kurs['azwv_knr'] = cs8($kurs['azwv_knr']);
            $kurs['msgtooperator'] = cs8($kurs['msgtooperator']);
            $kurs['niveau_stufe'] = cs8($kurs['niveau_stufe']);
            $kurs['sprachlevel'] = cs8($kurs['sprachlevel']);
            $kurs['kompetenzen'] = cs8($kurs['kompetenzen']);
            $kurs['lernform_stichwort'] = cs8($kurs['lernform_stichwort']);
            $kurs['foerderung_stichwort'] = cs8($kurs['foerderung_stichwort']);
            $kurs['einstieg'] = cs8($kurs['einstieg']);
            $kurs['promote_mode'] = cs8($kurs['promote_mode']);
            $kurs['promote_param'] = cs8($kurs['promote_param']);
            $kurs['promote_active'] = cs8($kurs['promote_active']);
            for ($d = 0; $d < sizeof((array)$kurs['durchf']); $d++) {
                $kurs['durchf'][$d]['nr'] = cs8($kurs['durchf'][$d]['nr']);
                $kurs['durchf'][$d]['ort'] = cs8($kurs['durchf'][$d]['ort']);
                $kurs['durchf'][$d]['stadtteil'] = cs8($kurs['durchf'][$d]['stadtteil']);
                $kurs['durchf'][$d]['strasse'] = cs8($kurs['durchf'][$d]['strasse']);
                $kurs['durchf'][$d]['preishinweise'] = cs8($kurs['durchf'][$d]['preishinweise']);
                $kurs['durchf'][$d]['bemerkungen'] = cs8($kurs['durchf'][$d]['bemerkungen']);
            }

            if (sizeof((array)$kurs['error'])) {
                $topnotes = $kurs['error'];
                $showForm = false;
            }
        }

        // page out
        $kursId__ = -666; // use $kurs['id'] instead - esp. for ID #0, the ID may change in saveKursToDb()!
        $pageTitle = $kurs['id'] == 0 ? 'Neuer Kurs' : 'Kurs bearbeiten';
        echo $this->framework->getPrologue(array('title' => $pageTitle, 'bodyClass' => 'wisyp_edit'));

        if (!$_SESSION['statusMsgShown']) {
            $db->query("SELECT pflege_msg FROM anbieter WHERE id=" . $_SESSION['loggedInAnbieterId']);
            $db->next_record();
            $msg = trim($db->fcs8('pflege_msg'));
            if ($msg != '') {
                echo '<div id="pflege_msg"><h1>Nachricht vom Portalbetreiber</h1>';
                $wiki2html =& createWisyObject('WISY_WIKI2HTML_CLASS', $this->framework);
                echo $wiki2html->run($msg);
                echo "<p><a href=\"#\" onclick=\"$('#pflege_msg').hide();\">[ Nachricht schlie&szlig;en ]</a></p></div>";
            }
            $_SESSION['statusMsgShown'] = true;
        }

        echo "\n\n<h1>$pageTitle</h1>\n";

        if (sizeof((array)$topnotes)) {
            echo "<p class=\"wisy_topnote\">" . implode('<br />', $topnotes) . "</p>";
        } else {
            $temp = $this->renderVollstMsg($kurs['id'], true);
            echo $temp ? "<p>$temp</p>" : '';
        }

        echo '<form id="myForm" action="edit" method="post" name="kurs">' . "\n";
        echo '<input type="hidden" name="action" value="ek" /> ' . "\n";
        echo '<input type="hidden" name="subseq" value="1" /> ' . "\n";
        echo '<input type="hidden" name="id" value="' . $kurs['id'] . '" /> ' . "\n";
        echo '<input type="hidden" name="bwd" value="' . htmlspecialchars($this->bwd) . '" /> ' . "\n";

        if ($showForm) {
            $hintcss = ""; // " display: inline; ";

            if ($_COOKIE['hints'] == 2) {
                $hintcss = " display: none; ";
            }
            echo '<br />';
            echo '<table cellspacing="2" cellpadding="0" width="100%">';
            echo '<tr>';
            //    echo '<td colspan="3"><strong>Bearbeitungshinweise: </strong>';
            //    echo '<a class="edit_hint_a edit_hint_disable" onClick="document.cookie = \'hints=1\'; $(\'.edithinweis\').css(\'display\',\'inline\'); $(\'.edit_hint_a, .edit_hint_b\').removeClass(\'edit_hint_enable\').removeClass(\'edit_hint_disable\'); $(this).removeClass(\'edit_hint_disable\').addClass(\'edit_hint_disable\');">einblenden</a> / ';
            //    echo '<a class="edit_hint_b" onClick="document.cookie=\'hints=2\'; $(\'.edithinweis\').css(\'display\',\'none\'); $(\'.edit_hint_a, .edit_hint_b\').removeClass(\'edit_hint_enable\').removeClass(\'edit_hint_disable\'); $(this).removeClass(\'edit_hint_disable\').addClass(\'edit_hint_disable\');">ausblenden</a> </span><br><br></td>';
            echo '</tr>';

            ############################################################################################################

            /*     // STATUS
                 echo '<tr><td colspan="2" style="text-align: right"><strong style="color: black">Status: </strong><span style="color: #1c94c4">Entwurf</span>';
                 //echo '<td><span style="color: #1c94c4">Entwurf</span></td>';
                 echo '</td></tr>';*/

            ############################################################################################################

            // Kategorie
            $this->selectCategory($kurs['category']);


            echo '<div class="niveauInfo modal-kategoriebg" style="display: none">';
            echo '<div class="modal-kategorie"><span class="niveauInfo-close">&times;</span>';
            echo '<h1 >Bitte w&auml;hlen Sie zuerst eine Kategorie aus.</h1><hr class="kategorie-hr">';
            echo '<br><span>Ihre Auswahl beeinflusst, welche Eingabefelder f&uuml;r Ihren Kurs angezeigt werden.</span>';
            //  echo '<a href="#" class="kategorie-modal-button">Kategorie ausw&auml;hlen</a>';
            echo '</div>';
            echo '</div>';


            ############################################################################################################

            // TITEL

            $useredit_courseTitlePH = $this->framework->iniRead('useredit.courseTitlePH', '');

            echo '<tr>';
            echo '<td width="10%" valign="top"><strong>Kurstitel<span style="color:red">*</span></strong></td>';
//<br><span style="color: rgb(220,20,60);font-size: 11px;margin: 0">Pflichtfeld</span></td>';
            echo '<td width="90%" valign="top"><div class="wisy-textfield-content">';
            if ($kurs['rights_editTitel']) {
                $this->controlText('titel', (PHP7 ? $kurs['titel'] : utf8_decode($kurs['titel'])), 150, 255, '', '', '.{3,250}', ($useredit_courseTitlePH == 1 ? $useredit_courseTitlePH : 'Name/Titel Ihres Angebots'), 0);
                echo '<br />';
            } else {
                echo '<strong>' . htmlspecialchars($kurs['titel']) . '</strong>';
                $this->controlHidden('titel', $kurs['titel']);
            }
            echo '</div><tr><td><br></td></tr>';

            ############################################################################################################

            $useredit_courseDescriptionPH = $this->framework->iniRead('useredit.courseDesciptionExample', '');

            // KURSBESCHREIBUNG
            echo '<tr>';
            echo '<td valign="top" ><strong>Kursbeschreibung<span style="color:red">*</span></strong></td>';//&nbsp;<br><span style="color: rgb(220,20,60);font-size: 11px;margin: 0">Pflichtfeld</span></td>';
            echo '<td>';
            echo '<div class="wisy-renderEdit-content">';
            echo $this->renderEditorToolbar(false);
            echo '<textarea class="wisy-kursbeschreibung-text" name="beschreibung" rows="14"  placeholder="' . (($useredit_courseDescriptionPH) ? $useredit_courseDescriptionPH : 'Beschreiben Sie Ihr Angebot m&ouml;glichst vollst&auml;ndig und umfassend.') . '">' . htmlspecialchars($kurs['beschreibung']) . '</textarea>';
            echo '</div>';
            echo '</td>';
            echo '</tr>';

            ############################################################################################################

            // LERNZIEL
            $useredit_learninggoalhelp = $this->framework->iniRead('useredit.learninggoalhelp', '');
            $useredit_learninggoalPH = $this->framework->iniRead('useredit.learninggoalExample', '');

            echo '<tr>';
            echo '<td valign="top" ><strong>Lernziele</strong>';
            if ($useredit_learninggoalhelp) echo $this->renderhoverInfo($useredit_learninggoalhelp) . '</td>';
            else echo $this->renderhoverInfo('Tragen Sie hier bitte ein, welche Kompetenzen bzw. F&auml;higkeiten Ihre
            TN nach Abschluss der Kursteilnahme erlangen und in welcher Breite und Tiefe Wissen vermittelt wird.
            Nutzen Sie bei der Formulierung bitte Verben in ihrer aktiven Form und erg&auml;nzen Sie, worauf sich das Wissen und
            K&ouml;nnen konkret bezieht. Bsp: Die TN k&ouml;nnen eine Maschine bedienen, Pl&auml;ne erstellen, Prozesse planen.') . '</td>';
            echo '<td><div class="wisy-renderEdit-content">';
            echo $this->renderEditorToolbar(false);
            echo '<textarea class="wisy-lernziel-text" name="lernziele" rows="10" placeholder="' . (($useredit_learninggoalPH) ? $useredit_learninggoalPH : 'Formulieren Sie hier die Lernziele Ihres Angebots.&#10;(Bsp.: Expertenstandards sicher anwenden und evaluieren. etc.)') . '">' . htmlspecialchars($kurs['lernziele']) . '</textarea>';
            echo '</div></td>';
            echo '</tr>';

            ############################################################################################################

            /*            // Voraussetzungen
                        $useredit_courseRequirementhelp = $this->framework->iniRead('useredit.courseRequirementhelp', '');
                        $useredit_courseRequirementPH = $this->framework->iniRead('useredit.courseRequirementExample', '');

                        echo '<tr>';
                        echo '<td valign="top" width="10%"><strong>Voraussetzungen</strong>';
                        if ($useredit_courseRequirementhelp) echo $this->renderhoverInfo($useredit_courseRequirementhelp) . '</td>';
                        else echo $this->renderhoverInfo('Formulieren Sie hier Voraussetzungen f&uuml;r eine erfolgreiche Kursteilnahme. z.B.: Abschl&uuml;sse, abgeschlossene Kurse,
                        Ausbildungen, Kenntnisse die vorliegen m&uuml;ssen. Bedenken Sie, je vollst&auml;ndiger die Angaben zu ihrem Kurs sind, desto besser erfolgt die Zuordnung auf
                        Suchanfragen.') . '</td>';
                        echo '<td><div class="wisy-renderEdit-content">';
                        echo $this->renderEditorToolbar(false);
                        echo '<textarea class="wisy-voraussetzung-text" name="voraussetzungen" rows="10" placeholder="' . (($useredit_courseRequirementPH) ? $useredit_courseRequirementPH : 'Nennen Sie n&ouml;tige Voraussetzungen/Vorkenntnisse der Teilnehmenden. &#10;(Bsp.: Grundkenntnisse in den Bereichen Ern&auml;hrungslehre, Lebensmittelkunde, Ern&auml;hrung, etc.)') . '">' . htmlspecialchars($kurs['vorraussetzungen']) . '</textarea>';
                        echo '</div></td>';
                        echo '</tr>';

                        ############################################################################################################

                        // ZIELGRUPPE
                        $useredit_courseTargetgrouphelp = $this->framework->iniRead('useredit.courseTargetgrouphelp', '');
                        $useredit_courseTargetgroupPH = $this->framework->iniRead('useredit.courseTargetgroupExample', '');

                        echo '<tr>';
                        echo '<td valign="top" ><strong>Zielgruppe</strong>';
                        if ($useredit_courseTargetgrouphelp) echo $this->renderhoverInfo($useredit_courseTargetgrouphelp) . '</td>';
                        else echo $this->renderhoverInfo('Nennen Sie hier, falls sich Ihr Angebot an bestimmte Personen- oder Berufsgruppen richtet,
                        die entsprechende Zielgruppe, z.B.: Arbeitssuchende, Besch&auml;ftigte, Migrant:innen, bestimmte Berufsgruppen, Senioren') . '</td>';
                        echo '<td><div class="wisy-renderEdit-content">';
                        echo $this->renderEditorToolbar(false);
                        echo '<textarea class="wisy-zielgruppe-text" name="zielgruppe" rows="5" placeholder="' . (($useredit_courseTargetgroupPH) ? $useredit_courseTargetgroupPH : 'Beschreiben Sie die Zielgruppe Ihres Angebotes. &#10;(Bsp.: Selbst&auml;ndig, Berufst&auml;tige, etc.)') . '">' . htmlspecialchars($kurs['zielgruppe']) . '</textarea>';
                        echo '</div></td>';
                        echo '</tr>';*/

            ############################################################################################################

            // THEMA
            $useredit_courseTopichelp = $this->framework->iniRead('useredit.courseTopichelp', '');
            $useredit_courseTopicQuestion = $this->framework->iniRead('useredit.courseTopicQuestion', '');
            $useredit_courseMainTopicQuestion = $this->framework->iniRead('useredit.courseMainTopicQuestion', '');
            $useredit_courseSubTopicQuestion = $this->framework->iniRead('useredit.courseSubTopicQuestion', '');
            $useredit_courseTopicStatus = $this->framework->iniRead('useredit.courseTopicStatus', '');

            if (!$useredit_courseTopicStatus) {
                echo '<tr>';
                echo '<td><strong>Thema</strong>';
                if ($useredit_courseTopichelp) echo $this->renderhoverInfo($useredit_courseTopichelp) . '</td>';
                else echo $this->renderhoverInfo('Damit Ihr Kurs &uuml;ber den Themeneinstieg unseres Kursportals gefunden wird, ist es wichtig Ihrem Angebot das
            passende Thema zuzuweisen. Die Haupt- und Unterkategorien sind dabei vordefiniert. Sie k&ouml;nnen das Feld &uuml;berspringen, wenn Sie sich nicht sicher
            sind, welches Thema zu Ihrem Angebot passt. Dann vergibt unsere Redaktion das Thema f&uuml;r Ihren Kurs.') . '</td>';
                echo '<td>';
                echo '  <div class="wisy-thema-kategorie"><h3>';
                if ($useredit_courseTopicQuestion) echo $useredit_courseTopicQuestion;
                else echo 'Welchem Thema soll Ihr Kurs zugeordnet werden?';
                echo '</h3>';
                echo '<div> <p>';
                if ($useredit_courseMainTopicQuestion) echo $useredit_courseMainTopicQuestion;
                else echo 'W&auml;hlen Sie zun&auml;chst eine Haupt- und anschlie&szlig;end eine Unterkategorie aus.';
                echo '</p>';
                echo '<label for="hauptkategorie">Hauptkategorie:</label><select id="hauptkategorie" class="thema-select-kategorie wisy-select-hauptkategorie" name="hauptkategorie"><option>Bitte w&auml;hlen Sie aus</option>';
                $this->kuerzelHauptkategorie();
                echo '</select></div>';
                echo '<div>';
                echo '<p>';
                if ($useredit_courseSubTopicQuestion) echo $useredit_courseSubTopicQuestion;
                else echo 'Die angezeigten Unterkategorien variieren je nach gew&auml;hlter Hauptkategorie. Sollte das passende nicht dabei sein, w&auml;hlen Sie ggf. eine andere Hauptkategorie.';
                echo '</p>';
                echo '<label for="unterkategorie">Unterkategorie: </label><select id="unterkategorie" class="thema-select-kategorie wisy-select-unterkategorie" name="unterkategorie"><option>Bitte w&auml;hlen Sie aus</option>';
                echo '</select></div>';
                if ($kurs['thema']) {
                    echo '<div class="wisy-selected-thema"><span> Dieser Kurs ist mit dem Thema <b><u>' . $this->getHauptthemaName($kurs['thema']) . '</u></b> verkn&uuml;pft</span>';
                    echo '<input type="hidden" value="' . $kurs['thema'] . '" name="selectedThema">';
                    echo '<button id="selectedThema" value="' . $kurs['thema'] . '" onclick="deleteSelectedThema(this)">Thema &auml;ndern</button>';
                    echo '</div>';
                }
                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }

            ############################################################################################################


            if ($kurs['niveau_stw']) {
                //KURSNIVEAU
                echo '<tr class="wisy-niveau-block">';
                echo '<td valign="top"><strong>Kursniveau<span style="color:red">*</span></strong></td>';//&nbsp;<br><span class="wisy-kursniveau-cell" style="color: rgb(220,20,60);font-size: 11px;margin: 0; display: none">Pflichtfeld</span></td>';
                echo '<td>';
                // Die Antwort zurückgeben
                // To-Do: If nicht sprachen, dann das, sonst function sprachen aufrufen
                echo '<div class="niveau-menu niveau-stufen">';
                $this->createNiveaustufe($kurs['niveau_stw']);
                echo '</td>';
                echo '</tr>';
            } elseif ($kurs['sprachlevel_stw']) {
                //KURSNIVEAU
                echo '<tr class="wisy-niveau-block">';
                echo '<td valign="top"><strong>Kursniveau<span style="color:red">*</span></strong></td>';//&nbsp;<br><span class="wisy-kursniveau-cell" style="color: rgb(220,20,60);font-size: 11px;margin: 0; display: none">Pflichtfeld</span></td>';
                echo '<td>';
                // Die Antwort zurückgeben
                // To-Do: If nicht sprachen, dann das, sonst function sprachen aufrufen
                echo '<div class="niveau-menu niveau-stufen">';
                $this->sprachniveaus($kurs['sprachlevel_stw']);
                echo '</td>';
                echo '</tr>';
            } else {
                //KURSNIVEAU
                echo '<tr class="wisy-niveau-block" style="display:none;">';
                echo '<td valign="top"><strong>Kursniveau<span style="color:red">*</span></strong></td>';//&nbsp;<br><span class="wisy-kursniveau-cell" style="color: rgb(220,20,60);font-size: 11px;margin: 0; display: none">Pflichtfeld</span></td>';
                echo '<td>';
                // Die Antwort zurückgeben
                // To-Do: If nicht sprachen, dann das, sonst function sprachen aufrufen
                echo '<div class="niveau-menu niveau-stufen">';
                echo '</td>';
                echo '</tr>';
            }


            ############################################################################################################

            //STICHWORTVORSCHLAEGE
            $useredit_keywordSuggestionhelp = $this->framework->iniRead('useredit.keywordSuggestionhelp', '');
            $useredit_keywordSuggestionWisyPH = $this->framework->iniRead('useredit.keywordSuggestionExample', '');
            $useredit_keywordSuggestionEscoPH = $this->framework->iniRead('useredit.keywordSuggestionEscoExample', '');
            $useredit_keywordSuggestionEscoQuestion = $this->framework->iniRead('useredit.keywordSuggestionEscoQuestion', '');
            $useredit_keywordSuggestionStatus = $this->framework->iniRead('useredit.keywordSuggestionStatus', '');

            if (!$useredit_keywordSuggestionStatus) {
                echo '<tr>';
                echo '<td width="12%" valign="top"><strong>Stichwortvorschl&auml;ge</strong>';
                if ($useredit_keywordSuggestionhelp) echo $this->renderhoverInfo($useredit_keywordSuggestionhelp) . '</td>';
                else echo $this->renderhoverInfo('Mit welchen Suchbegriffen sollte Ihr Angebot gefunden werden? Nennen Sie hier charakteristische Stichworte f&uuml;r Ihr
            Kursangebot. Blau markierte Stichworte sind bereits im System hinterlegt, eigene Suchworte erscheinen in gr&uuml;n und werden von der Redaktion gepr&uuml;ft, Ihr Kursangebot wird entsprechend verschlagwortet.') . '</td>';
                echo '<td><div class="stichwort-content">';
                echo '<h3>Stichw&ouml;rter suchen:</h3>';
                echo '<label><input type="text" id="stichwortvorschlag" class="wisy-stichwortvorschlag" placeholder="' . (($useredit_keywordSuggestionWisyPH) ? $useredit_keywordSuggestionWisyPH : 'Unter welchen Stichw&ouml;rtern soll Ihr Kurs gefunden werden?') . '"></label>';
                // echo '<label>' . $this->controlText('titel', (PHP7 ? $kurs['titel'] : utf8_decode($kurs['titel'])), 64, 250, '', '', '.{3,250}', 'Unter welchem Stichwort soll Ihr Kurs gefunden werden?', 0) . '<span id="add-stw">Einf&uuml;gen</span></label>';
                echo '<h3>Kompetenzen suchen:</h3>';
                echo '<label><input type="text" id="kompetenzvorschlag" class="wisy-stichwortvorschlag" placeholder="' . (($useredit_keywordSuggestionEscoPH) ? $useredit_keywordSuggestionEscoPH : 'Unter welchen Kompetenzen soll Ihr Kurs gefunden werden?') . '"></label>';
                echo '<h3>Kompetenzen automatisch generieren:</h3>';
                echo '<span class="wisy-kompetenzen-generieren">';
                if ($useredit_keywordSuggestionEscoQuestion) echo '<p>' . $useredit_keywordSuggestionEscoQuestion . '</p>';
                else echo '<p>Lassen Sie sich anhand Ihrer Kursbeschreibung Kompetenzen automatisch generieren und mit dem Kurs verkn&uuml;pfen.</p>';
                echo '<span id="wisy-esco-gen">Kompetenzen generieren</span></span>';
                echo '<h3>Bisherige Auswahl an Stichw&ouml;rtern & Kompetenzen</h3>';
                echo '<div class="stichwort-area"></div>';
                echo '</div></td>';
                echo '</tr>';

                echo ' <div id="loadingModal" class="wisy-esco-modal">
                        <div class="wisy-esco-modal-content">
                            <div class="wisy-esco-loader"></div>
                            <p>Kompetenzen werden generiert, dies kann etwas dauern...</p>
                        </div>
                    </div>';

                //<!-- Container für die versteckten HTML-Elemente -->
                echo '<div id="hiddenElementsContainer"></div>';
                if (!empty($kurs['kompetenzen'])) {
                    foreach ($kurs['kompetenzen'] as $stwname => $type) {
                        echo '<input type="hidden" name="editEsco_kompetenzen[]" value="' . $stwname . '+++' . $type . '">';
                    }
                }
            }


            ############################################################################################################

            //ABSCHLUSS
            /*
                        echo '<tr>';
                        echo '<td width="10 % " valign="top"><strong>Abschluss:</strong>';
                        echo $this->renderhoverInfo('W&auml;hlen Sie einen Abschluss aus. Es k&ouml;nnen nur qualifizierte und anerkannte Abschl&uuml;sse erfasst werden.') . '</td>';
                        // echo '<td><div class="abschluss - content">';
                        // echo '<label><input type="text" id="abschluss" placeholder="Abschluss suchen"></label>';
                        echo '<td><div class="wisy - abschluss - kategorie">';
                        echo '<label for="abschlussart"><input name="abschlussart" list="abschlussarten" class="abschluss - select" placeholder="Abschluss suchen..."><datalist id="abschlussarten">' . $this->getAbschluesse() . '</datalist></label>';
                        echo '</div></td>';
                        echo '</tr>';*/

            ############################################################################################################

            //LERNFORM

            $useredit_learningformhelp = $this->framework->iniRead('useredit.learningformhelp', '');
            $useredit_learningformQuestion = $this->framework->iniRead('useredit.learningformQuestion', '');

            echo '<tr>';
            echo '<td width="10 % " valign="top" ><strong>Lernform</strong>';
            if ($useredit_learningformhelp) echo $this->renderhoverInfo($useredit_learningformhelp) . '</td>';
            else echo $this->renderhoverInfo('W&auml;hlen Sie die Lernform Ihres Angebots aus. Sollte Ihr Kursangebot mehrere Lernformen integrieren,
            w&auml;hlen Sie bitte die Form mit dem Hauptanteil aus und erg&auml;nzen Sie ggfs. Hinweise oder Abweichungen in Ihrer Kursbeschreibung oder im
            Feld Bemerkung.') . '</td>';
            echo '<td><div class="wisy-lernform-kategorie">';
            if ($useredit_learningformQuestion) echo '<h3>' . $useredit_learningformQuestion . '</h3>';
            else echo '<h3>W&auml;hlen Sie eine Lernform aus.</h3>';
            if ($kurs['lernform_stichwort']) echo $this->getLernform($kurs['lernform_stichwort']);
            else echo $this->getLernform();
            echo '</div></td>';
            echo '</tr>';


            //FÖRDERUNGEN

            $useredit_fundinghelp = $this->framework->iniRead('useredit.fundinghelp', '');
            $useredit_fundingQuestion = $this->framework->iniRead('useredit.fundingQuestion', '');

            echo '<tr>';
            echo '<td width="10 % " valign="top" ><strong>F&ouml;rderprogramme</strong>';
            if ($useredit_fundinghelp) echo $this->renderhoverInfo($useredit_fundinghelp) . '</td>';
            else echo $this->renderhoverInfo(
                    'W&auml;hlen Sie alle zutreffenden F&ouml;rderprogramme f&uuml;r Ihr Angebot aus. Eine Mehrfachauswahl ist m&ouml;glich.
                F&uuml;r die Auswahl von Bildungsurlaub und Bildungsgutschein ist die Eingabe der entsprechenden Kontrollnummer notwendig.
                Bedenken Sie, je vollst&auml;ndiger die Angaben zu Ihrem Kurs sind, desto besser erfolgt die Zuordnung auf Suchanfragen.
                Sollte ein F&ouml;rderprogramm fehlen, k&ouml;nnen Sie der Redaktion einen Hinweis zukommen lassen.
                Nach Pr&uuml;fung der Daten wird dies ggfs. erg&auml;nzt.') . '</td>';
            echo '<td><div class="wisy-foerderungen-content">';
            echo '<div class="checkbox-container">';
            if ($useredit_fundingQuestion) echo '<h3>' . $useredit_fundingQuestion . '</h3>';
            else echo '<h3>Kann der Kurs von einem  F&ouml;rderprogramm gef&ouml;rdert werden?</h3>';
            if ($kurs['foerderung_stichwort']) echo $this->foerderungen($kurs['foerderung_stichwort']);
            else echo $this->foerderungen();
            echo '</div>';
            echo '<div class="input-container">';
            echo '<input class="wisy-foerderungsnummerA" type="text" placeholder="AZAV - Zertifikatsnummer eintragen. " name="foerderung_azav" value="' . htmlspecialchars($kurs['azwv_knr']) . '">';
            echo '<input class="wisy-foerderungsnummerB" type="text" placeholder="Bildungsurlaub Nummer eintragen. " name="foerderung_bn" value="' . htmlspecialchars($kurs['bu_nummer']) . '">';
            echo '</div>';
            echo '</div></td>';
            echo '</tr>';


            //DURCHFÜHRUNG
            // echo '<tr>';
            echo '<tr class="editDurchfRow">';
            echo '<td width="10%" valign="top" nowrap="nowrap"><strong>Durchf&uuml;hrung</strong>&nbsp;&nbsp;</td>';
            echo '<td><div class="wisy-durchf-content">';
            for ($d = 0; $d < sizeof((array)$kurs['durchf']); $d++) {
                $durchf = $kurs['durchf'][$d];
                echo '<small>';
                echo '<input type="hidden" name="durchfid[]" value="' . $durchf['id'] . '" class="hiddenId" />';
                //  echo '<a href="#" onclick="editDurchfKopieren($(this)); return false;" title="Eine Kopie dieser Durchf&uuml;hrung zur weiteren Bearbeitung anlegen">+kopieren</a> ';
                //  echo '<a href="#" onclick="editDurchfLoeschen($(this)); return false;" title="Diese Durchf&uuml;hrung l&ouml;schen">-l&ouml;schen</a> ';
                echo '</small>';
                echo '<div style="background-color: white;">';
                echo '<span style="float: right"><a href="#" onclick="editDurchfLoeschen($(this)); return false;" title="Diese Durchf&uuml;hrung l&ouml;schen"> <u>l&ouml;schen</u> </a></span> ';

                echo '<table cellspacing="6" cellpadding="0" class="wisy-durchf-tab">';
                echo '<tr>';
                //   echo '<td colspan="2" ><p class="edithinweis" style="' . $hintcss . '" >';
                //   echo '<strong>Hinweise</strong>:<br><small>';
                // echo $this->getEditHelpText('durchfuehrung', $controlTags['Glossar:Durchfuehrungen']);
                //  echo '<br>' . $this->framework->getGrpSetting($db, $kurs['id'], 'useredit.durchfuehrunghinweise');
                echo '</small></p>';
                echo '</tr>';

                // DURCHFUEHRUNGS-NR
                echo '<tr>';
                echo '<td valign="top" nowrap="nowrap">Durchf&uuml;hrungs-Nr.:&nbsp;&nbsp;&nbsp;</td>';
                echo '<td>';
                $this->controlText('nr[]', (PHP7 ? $durchf['nr'] : utf8_decode($durchf['nr'])), 64, 64, '', 'k. A.', '', 'Falls vorhanden, erfassen Sie hier die Referenznummer Ihres Kurses.', 0);
                echo '</td>';
                echo '</tr>';

                // TERMIN
                echo '<tr>';
                echo '<td valign="top">Termin:</td>';
                echo '<td>';

                if ($kurs['id'] == 0) {
                    $this->controlText('beginn[]', '', 18, 10, '', '', '^[0-9]{2}[.][0-9]{2}[.][0-9]{4}$', 'tt.mm.jjjj', 0, 'date');
                    echo ' bis ';
                    $this->controlText('ende[]', '', 18, 10, '', '', '[0-9]{2}[.][0-9]{2}[.][0-9]{4}', 'tt.mm.jjjj', 0, 'date');
                    //  echo '<br> ';
                } else {
                    $temp = sql_date_to_human($durchf['beginn'], 'dateopt editable');
                    $timestep = strtotime($temp);
                    $formattedDate = date('Y-m-d', $timestep);
                    $this->controlText('beginn[]', $formattedDate, 18, 10, '', '', '^[0-9]{2}[.][0-9]{2}[.][0-9]{4}$', 'tt.mm.jjjj', 0, 'date');
                    echo ' bis ';
                    $temp = sql_date_to_human($durchf['ende'], 'dateopt editable');
                    $timestep = strtotime($temp);
                    $formattedDate = date('Y-m-d', $timestep);
                    $this->controlText('ende[]', $formattedDate, 18, 10, '', '', '[0-9]{2}[.][0-9]{2}[.][0-9]{4}', 'tt.mm.jjjj', 0, 'date');
                    //  echo '<br> ';
                }


                echo ' &nbsp;&nbsp; ';
                if ($kurs['einstieg']) {
                    echo '<input class="wisy-durchf-einstieg" type="checkbox" name="einstieg[]" value="1" checked>';
                } else {
                    echo '<input class="wisy-durchf-einstieg" type="checkbox" name="einstieg[]" value="1" >';
                }
                echo '<span style="margin-left: 15px;">Einstieg jederzeit m&ouml;glich</span>';
                echo '</td></tr><tr><td></td><td><label title="">';
                global $codes_kurstage;
                $bits = explode('###', $codes_kurstage);
                for ($i = 0; $i < sizeof($bits); $i += 2) {
                    // normally, we would use the normal <input type="checkbox" /> - however this does
                    // not work with our array'ed durchf‚àö¬∫hrungen as a checkbox value is not appended to an array it it is not checked ...
                    echo '<span>'; // needed to get the both items on one level
                    $value = $durchf['kurstage'] & intval($bits[$i]) ? 1 : 0;
                    echo "<input type=\"hidden\" name=\"kurstage$i" . "[]\" value=\"$value\" />";
                    echo '<span style="border: 1px solid black; margin: 0 15px 0 0;" onclick="editWeekdays($(this));" class="' . ($value ? 'wisy_editweekdayssel' : 'wisy_editweekdaysnorm') . '">' . trim(str_replace('.', '', $bits[$i + 1])) . '</span>';
                    echo '</span>';
                }

                $do_expand = false;
                if ($durchf['beginnoptionen']) {
                    $do_expand = true;
                }
                if (berechne_dauer($durchf['beginn'], $durchf['ende']) == 0 && $durchf['dauer'] != 0) {
                    $do_expand = true;
                }
                if (berechne_tagescode($durchf['zeit_von'], $durchf['zeit_bis'], $durchf['kurstage']) == 0 && $durchf['tagescode'] != 0) {
                    $do_expand = true;
                }

                $titleBeginnoptionen = 'Hiermit k&ouml;nnen Sie f&uuml;r diese Durchf&uuml;hrung eine Terminoption festlegen, etwa wenn die Durchf&uuml;hrung regelm&auml;&szlig;ig stattfindet';
                $styleBeginnoptionen = '';
                if (!$do_expand) {
                    echo "<span class=\"editBeginnoptionenLink\"> <a href=\"#\" onclick=\"editShowHide($(this), '.editBeginnoptionenDiv', '.editBeginnoptionenLink'); return false;\" title=\"$titleBeginnoptionen\"><small style='color: blue; font-size: 14px;'><u>Terminoptionen</u></small></a></span>";
                    $styleBeginnoptionen = ' style="display:none;" ';
                }

                echo "</br><div class=\"editBeginnoptionenDiv\" $styleBeginnoptionen>";
                echo '</br><label>';
                echo "Terminoptionen: ";
                $this->controlSelect('beginnoptionen[]', $durchf['beginnoptionen'], $GLOBALS['codes_beginnoptionen']);
                //  echo ' <small class="edithinweis" style="' . $hintcss . '" >' . $this->getEditHelpText('beginnoptionen', $controlTags['Glossar:beginnoptionen']) . '</small>';

                echo "<br />Dauer: ";
                $this->controlSelect('dauer[]', $durchf['dauer'], $GLOBALS['codes_dauer']);
                //   echo ' <small class="edithinweis" style="' . $hintcss . '" >' . $this->getEditHelpText('dauer', $controlTags['Glossar:dauer']) . '</small>';

                echo "<br />Tagescode: ";
                $this->controlSelect('tagescode[]', $durchf['tagescode'], $GLOBALS['codes_tagescode']);
                //  echo ' <small class="edithinweis" style="' . $hintcss . '" >' . $this->getEditHelpText('tagescode', $controlTags['Glossar:tagescode']) . '</small>';
                echo '</label>';
                echo '</div>';
                echo '</td>';
                echo '</tr>';
                echo '</label></td></tr>';

                echo ' &nbsp;&nbsp; ';
                echo '</td></tr>';

                echo '<tr><td valign="top">Uhrzeit:</td>';

                echo '<td>';
                $this->controlText('zeit_von[]', $durchf['zeit_von'], 12, 5, '', '', '[0-9]{2}[:][0-9]{2}', 'hh:mm', 0, 'time');
                echo ' bis ';
                $this->controlText('zeit_bis[]', $durchf['zeit_bis'], 12, 5, '', '', '[0-9]{2}[:][0-9]{2}', 'hh:mm', 0, 'time');
                echo ' Uhr';
                echo '</tr>';

                //HINWEISE
                echo '<tr>';
                echo '<td valign="top">Hinweise:</td>';
                echo '<td>';

                if ($durchf['bemerkungen']) $kursHinweis = $this->filterKursHinweis($durchf['bemerkungen']);
                $this->controlText('bemerkungen[]', $kursHinweis, 64, 255, 'Geben Sie hier Ihre Bemerkungen ein', 'bemerkungen.', '', '', 0);

                echo '</td>';
                echo '</tr>';

                echo '<tr>';
                echo '<td valign="top">Stunden:</td>';
                echo '<td>';
                $temp = $durchf['stunden'] == 0 ? '' : $durchf['stunden'];
                $this->controlText('stunden[]', $temp, 4, 4, 'Geben Sie hier - soweit bekannt - die Gesamtzahl der Unterrichtsstunden ein.', 'k. A.', '[0-9]{1,4}', '', 0);
                echo ' Unterrichtsstunden mit max. ';
                $temp = $durchf['teilnehmer'] == 0 ? '' : $durchf['teilnehmer'];
                $this->controlText('teilnehmer[]', $temp, 3, 3, 'Geben Sie hier - soweit bekannt - die maximale Anzahl von Teilnehmenden ein, die insgesamt diese Durchf&uuml;hrung belegen werden.', 'k. A.', '[0-9]{1,3}', '', 0);
                echo ' Teilnehmenden';
                echo '</td>';
                echo '</tr>';
                echo '<tr>';
                echo '<td valign="top">Gesamtpreis inkl. MwSt:</td>';
                echo '<td>';

                $temp = $durchf['preis'] == -1 ? '' : $durchf['preis'];
                $this->controlText('preis[]', $temp, 5, 5, 'Geben Sie hier - soweit bekannt - den Gesamtpreis inkl. MwSt dieser Durchf&uuml;hrung in Euro ohne Nachkommastellen ein; geben Sie eine Null f&uuml;r &quot;kostenlos&quot; ein.', 'k. A.', '[0-9]{1,5}', '', 0);
                echo '&nbsp;EUR';

                if ($durchf['sonderpreistage'] == 0) {
                    $durchf['sonderpreistage'] = '';
                }
                if ($durchf['sonderpreis'] == -1 || $durchf['sonderpreistage'] == '') {
                    $durchf['sonderpreis'] = '';
                }

                $styleSonderpreis = '';
                if (!$durchf['sonderpreis']) {
                    echo "<span class=\"editSonderpreisLink\"> <a href=\"#\" onclick=\"editShowHide($(this), '.editSonderpreisDiv', '.editSonderpreisLink'); return false;\" title=\"Sonderpreis f&uuml;r diese Durchf&uuml;hrung hinzuf&uuml;gen\"><strong>+Sonderpreis</strong></a></span>";
                    $styleSonderpreis = ' style="display:none;" ';
                }

                $stylePreishinweise = '';
                if (!$durchf['preishinweise']) {
                    echo "<span class=\"editPreishinweiseLink\"> <a href=\"#\" onclick=\"editShowHide($(this), '.editPreishinweiseDiv', '.editPreishinweiseLink'); return false;\" title=\"Preishinweise hinzuf&uuml;gen\"><strong>+Preishinweise</strong></a></span>";
                    $stylePreishinweise = ' style="display:none;" ';
                }

                echo "<div class=\"editSonderpreisDiv\" $styleSonderpreis>";
                echo 'ab ';
                $this->controlText('sonderpreistage[]', $durchf['sonderpreistage'], 3, 3, 'Anzahl der Tage vor Beginntermin, wo der nachstehende Sonderpreis gelten soll.', 'k. A.', '[0-9]{1,3}', '', 0);
                echo ' Tagen vor Beginn dieser Durchf&uuml;hrung gilt ein erm&auml;&szlig;gter Sonderpreis von&nbsp;';
                $this->controlText('sonderpreis[]', $durchf['sonderpreis'], 5, 5, 'Sonderpreis dieser Durchf&uuml;hrung in Euro ohne Nachkommastellen.', 'k. A.', '[0-9]{1,5}', '', 0);
                echo '&nbsp;EUR';
                echo "</div>";

                echo "<div class=\"editPreishinweiseDiv\" $stylePreishinweise>";
                echo 'Preishinweise: ';
                $this->controlText('preishinweise[]', (PHP7 ? $durchf['preishinweise'] : utf8_decode($durchf['preishinweise'])), 50, 200, 'Geben Sie hier eventuelle sonstige Anmerkungen zum Preis ein.', '', '', 0); // utf8_decode shouln't be necessary
                echo "</div>";

                echo '</td>';
                echo '</tr>';
                echo '<tr>';
                echo '<td valign="top">Veranstaltungsort:</td>';
                echo '<td>';
                $this->controlText('strasse[]', (PHP7 ? $durchf['strasse'] : utf8_decode($durchf['strasse'])), 32, 100, 'Geben Sie hier - soweit bekannt und eindeutig - die Strasse und die Hausnummer des Veranstaltungsortes ein.', 'Strasse und Hausnr.', '', 'Stra&szlig;e und Hausnummer', 0); // utf8_decode shouln't be necessary

                echo ' &nbsp; ';

                $this->controlText('plz[]', $durchf['plz'], 12, 16, 'Geben Sie hier - soweit bekannt und eindeutig - die Postleitzahl des Veranstaltungsortes ein', 'PLZ', '', 'PLZ', 0);
                echo ' ';
                $this->controlText('ort[]', (PHP7 ? $durchf['ort'] : utf8_decode($durchf['ort'])), 16, 60, 'Geben Sie hier - soweit bekannt und eindeutig - den Ort bzw. die Stadt, in der die Veranstaltung stattfindet ein.', 'Ort', '', 'Ort', 0); // utf8_decode shouln't be necessary

                $this->controlHidden('stadtteil[]', $durchf['stadtteil']);
                $this->controlHidden('stadtteil_for[]', $durchf['strasse'] . ',' . $durchf['plz'] . ',' . $durchf['ort']);


                echo '</td>';
                echo '</tr>';

                echo '<tr>';
                // echo '<td valign="top">Barrierefreier Zugang:</td>';
                echo '<td></td>';
                echo '<td>';
                if ($durchf['rollstuhlgerecht']) {
                    echo '<input type="checkbox" name="rollstuhlgerecht[]" value="1" checked>';
                } else {
                    echo '<input type="checkbox" name="rollstuhlgerecht[]" value="1" >';
                }
                echo '<span style="margin-left: 15px;">Barrierefreier Zugang</span>';
                echo '</td>';
                echo '</tr>';

                echo '<tr>';
                echo '<td valign="top">Kurs-URL:</td>';
                echo '<td>';


                if ($durchf['bemerkungen']) $kursUrl = $this->filterKursUrl($durchf['bemerkungen']);
                $this->controlText('url[]', $kursUrl, 64, 255, 'Geben Sie hier Ihre Bemerkungen ein', 'bemerkungen', '', '', 0);

                echo '</td>';
                echo '</tr>';
                echo '<tr>';
                echo '<td colspan="2">';
                echo '<a href="#" onclick="editDurchfKopieren($(this)); return false;" title="Eine Kopie dieser Durchf&uuml;hrung zur weiteren Bearbeitung anlegen."><span class="wisy-durchf-icon">+</span><u>Weitere Durchf&uuml;hrung erfassen</u></a> ';
                echo '</td>';
                echo '</tr>';
                echo '</table>';


                // Nachricht an die Redaktion
                $useredit_nachrichtStatus=$this->framework->iniRead('useredit.nachrichtRedaktion','');
                if ($useredit_nachrichtStatus){
                    echo '<tr>';
                    echo '<td width="10%" valign="top" nowrap="nowrap"></td>';
                    echo '<td><div class="wisy-nachricht-content"><p>Haben Sie noch eine Nachricht an uns?</p>';
                    echo $this->controlText('msgtooperator', $kurs['msgtooperator'], 150, 255, 'Falls Sie w&uuml;nsche haben, Bitte tragen SIe hier Ihre Nachricht an die Redaktion.', '', '', 'Hinweise an die Redaktion');
                    echo '</div></td>';
                    echo '</tr>';
                }

                echo '<tr>';
                echo '<td></td><td>';
                echo '<input id="wisy-vorschau" class="wisy-vorschau" type="button" name="vorschau" value="Entwurf speichern" title ="Entwurf speichern.">';
                echo '<input class="wisy-vorschau" style="background-color: rgba(0,0,0,0.1); color: black; margin-left: 250px" type="submit" name="cancel" value="Abbruch" title="&Auml;nderungen verwerfen und Kurs nicht speichern" />' . "\n";
                echo '</td>';
                echo '</tr>';

                echo '</div>';
                echo '</td>';
                echo '</tr>';
                echo '</table>';
                echo '</div></td>';
                echo '</tr>';

                echo '</td>';
                echo '</tr>';

            }

            echo '</table>';

        }


        echo '<p>' . "\n";

        echo '<div class="niveauInfo wisy-vorschau-modal">';
        echo '<div id="wisy-vorschau-area" class="wisy-vorschau-area"><h1 style="float: left">Kurs-Vorschau</h1><span class="niveauInfo-close">&times;</span>';
        echo '<div class="wisy-vorschau-content"><table class="wisy-vorschau-table">';

        echo '<thead><tr>';
        echo '<th style="font-weight: bold; text-align: left; font-size: 20px">Kurstitel</th></tr></thead>';
        echo '<thead><tr>';
        echo '<tbody><tr><td><div class="wisy-vorschau-kurstitel">Sie haben keinen Kurstitel hinzugef&uuml;gt!</div></td></tr></tbody>';

        echo '<th style="font-weight: bold; text-align: left; font-size: 20px">Inhalt</th></tr></thead>';
        echo '<tbody><tr><td><div class="wisy-vorschau-inhalt">Sie haben keine Kursbeschreibung hinzugef&uuml;gt!</div></td></tr></tbody>';
        //  echo '</tr>';

        echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Lernziel</th></tr></thead>';
        echo '<tbody><tr><td><div class="wisy-vorschau-lernziel">Sie haben kein Lernziel hinzugef&uuml;gt!</div></td></tr></tbody>';

        /*        echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Voraussetzungen</th></tr></thead>';
                echo '<tbody><tr><td><div class="wisy-vorschau-voraussetzung">Sie haben keine Voraussetzung hinzugef&uuml;gt!</div></td></tr></tbody>';

                echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Zielgruppe</th></tr></thead>';
                echo '<tbody><tr><td><div class="wisy-vorschau-zielgruppe">Sie haben keine Zielgruppe hinzugef&uuml;gt!</div></td></tr></tbody>';*/

        if (!$useredit_courseTopicStatus) {
            echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Thema</th></tr></thead>';
            echo '<tbody><tr><td><div class="wisy-vorschau-thema">Sie haben kein Thema hinzugef&uuml;gt!</div></td></tr></tbody>';
        }

        echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Kursniveau</th></tr></thead>';
        echo '<tbody><tr><td><div class="wisy-vorschau-kursniveau">Sie haben kein Kursniveau hinzugef&uuml;gt!</div></td></tr></tbody>';

        //     echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Abschluss</th></tr></thead>';
        //     echo '<tbody><tr><td><div class="wisy-vorschau-abschluss">Ihr Kurs hat keinen Abschluss!</div></td></tr></tbody>';

        echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Lernform</th></tr></thead>';
        echo '<tbody><tr><td><div class="wisy-vorschau-lernform">Sie haben keine Lernform hinzugef&uuml;gt!</div></td></tr></tbody>';

        echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">F&ouml;rderprogramme</th></tr></thead>';
        echo '<tbody><tr><td><div class="wisy-vorschau-foerderung">Ihr Kurs hat keine F&ouml;rderprogramme!</div></td></tr></tbody>';

        echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Stichwortvorschl&auml;ge</th></tr></thead>';
        echo '<tbody><tr><td><div class="wisy-vorschau-stichwort">Ihr Kurs hat keine F&ouml;rderungen!</div></td></tr></tbody>';

        if ($useredit_nachrichtStatus){
            echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Hinweise an die Redaktion</th></tr></thead>';
            echo '<tbody><tr><td><div class="wisy-vorschau-nachricht"></div></td></tr></tbody>';
        }

        echo '<thead><tr><th style="font-weight: bold; text-align: left; font-size: 20px">Durchf&uuml;hrung</th></tr></thead>';
        echo '<tr><td><div class="wisy-vorschau-durchfuehrung">';

        echo '<table id="vorschau-table" style="border: 1px solid black; width: 100%">';
        echo '<thead><tr><th>NR.</th><th>Zeiten</th><th>Dauer</th><th>ART</th><th>Preis</th><th>Ort</th><th>Bemerkungen</th></tr></thead>';
        //  echo '<tbody><tr><td class="vorschau-durchf-nr"></td><td class="vorschau-durchf-beginn"></td><td class="vorschau-durchf-dauer"></td><td class="vorschau-durchf-art"></td><td class="vorschau-durchf-preis"></td><td class="vorschau-durchf-ort"></td><td class="vorschau-durchf-Bemerkung"></td></tr></tbody>';
        echo '<tbody></tbody>';

        echo '</table></div></td></tr>';

        echo '</table></div>';
        // echo '<input class="wisy-vorschau-speichern" type="submit" value="OK - Kurs speichern" name="kursspeichern" title="Alle &Auml;nderungen &uuml;bernehmen und Kurs speichern" style="font-weight: bold;" /> ' . "\n";
        echo '<input class="wisy-vorschau-speichern" type="submit" value="OK - Kurs speichern" name="kursspeichern" title="Alle &Auml;nderungen &uuml;bernehmen und Kurs speichern" style="font-weight: bold;" /> ' . "\n";
        echo '</div>'; // area ende
        echo '</div>'; // modal ende


        //     if ($showForm) {
        //   echo '<input type="submit" value="OK - Kurs speichern" title="Alle &Auml;nderungen &uuml;bernehmen und Kurs speichern" style="font-weight: bold;" /> ' . "\n";
        //     }

        // echo '<input type="submit" name="cancel" value="Abbruch" title="&Auml;nderungen verwerfen und Kurs nicht speichern" />' . "\n";
        echo '</p>' . "\n";

        if ($showForm) {
            echo '<p>';
            echo 'Ich versichere mit dem Speichern, dass ich den Beitrag selbst verfasst habe bzw. dass er keine fremden Rechte verletzt und willige ein, ihn unter der <a href="https://creativecommons.org/licenses/by-sa/3.0/deed.de" target="_blank" rel="noopener noreferrer" title="Weitere Informationen auf creativecommons.org">Lizenz f&uuml;r freie Dokumentation</a> zu ver&ouml;ffentlichen.';
            echo '<br><br>Hinweis: Neue Angebote, neue Durchf&uuml;hrungen und neue Stichworte stehen evtl. erst am n&auml;chsten Tag &uuml;ber die Stichwort-Suche zur Verf&uuml;gung.<br>Auf den Detailseiten sind &Auml;nderungen sofort sichtbar.';
            echo '</p>';
            if ($kurs['rights_editTitel']) {
                echo '<p>';
                echo 'Achtung: Neue Kurse m&uuml;ssen i.d.R. zun&auml;chst <b>von der Redaktion freigeschaltet</b> werden.
							Bis die neuen Kurse in den Ergebnislisten auftauchen, finden Sie sie unter der Ergebnisliste im Bereich <b>Kurse in Vorbereitung</b>.';
                echo '</p>';
            }
            echo '<p>';
            echo 'Weitere Optionen: ';
            echo '<a href="edit?action=ek&amp;id=' . $kurs['id'] . '&amp;deletekurs=1&amp;bwd=' . urlencode($this->bwd) . '" onclick="return editKursLoeschen($(this));">Diesen Kurs l&ouml;schen</a>';
            echo '</p>';
        }

        echo '</form>' . "\n";

        // $db->close();

        echo $this->framework->getEpilogue();
    }

    /**************************************************************************
     * Anbieterprofil bearbeiten
     **************************************************************************/

    function loadAnbieterFromDb($anbieterId)
    {
        // anbieter laden - das zurückgegebene Array ist wie bei loadKursFromPOST() beschrieben formatiert

        // kursdatensatz und alle durchfuehrungen lesen
        $db = new DB_Admin;
        $db->query("SELECT * FROM anbieter WHERE id=$anbieterId AND freigeschaltet = 1");
        if ($db->next_record()) {
            $anbieter = $db->Record;
        } else {
            $anbieter['error'][] = 'Die angegebene Kurs-ID existiert nicht oder nicht mehr.';
            // $db->close();
            return $anbieter;
        }

        // $db->close();
        return $anbieter;
    }

    function loadAnbieterFromPOST($anbieterId)
    {
        // kurs aus datanbank laden und mit den POST-Daten aktualisieren
        //
        // kurs ist ein array wie folgt:
        // 		$kurs['suchname']
        // 		$kurs['postname']
        //		.
        //		.
        //		$kurs['error'][]					(array mit Fehlermeldungen)

        $anbieter = $this->loadAnbieterFromDb($anbieterId);
        if (sizeof((array)$anbieter['error'])) {
            return $anbieter;
        }

        // adresse
        $posted = (PHP7 ? $_POST['strasse'] : utf8_decode($_POST['strasse']));
        if ($posted == 'Strasse und Hausnr.') $posted = '';
        $anbieter['strasse'] = $posted;

        $posted = (PHP7 ? $_POST['plz'] : utf8_decode($_POST['plz']));
        if ($posted == 'PLZ') $posted = '';
        $anbieter['plz'] = $posted;

        $posted = (PHP7 ? $_POST['ort'] : utf8_decode($_POST['ort']));
        if ($posted == 'Ort') $posted = '';
        $anbieter['ort'] = $posted;

        if (($anbieter['strasse'] . ',' . $anbieter['plz'] . ',' . $anbieter['ort']) == (PHP7 ? $_POST['stadtteil_for'] : utf8_decode($_POST['stadtteil_for']))) {
            $anbieter['stadtteil'] = $_POST['stadtteil'];
        } else {
            $anbieter['stadtteil'] = '';
        }

        // misc.
        $anbieter['rechtsform'] = intval((PHP7 ? $_POST['rechtsform'] : utf8_decode($_POST['rechtsform'])));
        $anbieter['gruendungsjahr'] = intval((PHP7 ? $_POST['gruendungsjahr'] : utf8_decode($_POST['gruendungsjahr'])));
        $anbieter['leitung_name'] = (PHP7 ? $_POST['leitung_name'] : utf8_decode($_POST['leitung_name']));
        $anbieter['homepage'] = (PHP7 ? $_POST['homepage'] : utf8_decode($_POST['homepage']));
        $anbieter['anspr_name'] = (PHP7 ? $_POST['anspr_name'] : utf8_decode($_POST['anspr_name']));
        $anbieter['anspr_zeit'] = (PHP7 ? $_POST['anspr_zeit'] : utf8_decode($_POST['anspr_zeit']));
        $anbieter['anspr_tel'] = (PHP7 ? $_POST['anspr_tel'] : utf8_decode($_POST['anspr_tel']));
        $anbieter['anspr_fax'] = (PHP7 ? $_POST['anspr_fax'] : utf8_decode($_POST['anspr_fax']));
        $anbieter['anspr_email'] = (PHP7 ? $_POST['anspr_email'] : utf8_decode($_POST['anspr_email']));
        $anbieter['pflege_name'] = (PHP7 ? $_POST['pflege_name'] : utf8_decode($_POST['pflege_name']));
        $anbieter['pflege_tel'] = (PHP7 ? $_POST['pflege_tel'] : utf8_decode($_POST['pflege_tel']));
        $anbieter['pflege_fax'] = (PHP7 ? $_POST['pflege_fax'] : utf8_decode($_POST['pflege_fax']));
        $anbieter['pflege_email'] = (PHP7 ? $_POST['pflege_email'] : utf8_decode($_POST['pflege_email']));

        return $anbieter;
    }

    function saveAnbieterToDb(&$newData)
    {
        // anbieter in datenbank speichern
        //
        // $kurs ist ein array wie unter loadAnbieterFromPOST() beschrieben, der Aufruf dieser Funktion kann dabei das
        // Feld $anbieter['error'] erweitern; alle anderen Felder werden nur gelesen

        $db = new DB_Admin;
        $user = $this->getAdminAnbieterUserId20();
        $today = strftime("%Y-%m-%d %H:%M:%S");
        $anbieterId = $newData['id'];
        $oldData = $this->loadAnbieterFromDb($anbieterId);
        if (sizeof((array)$oldData['error'])) {
            $newData['error'] = $oldData['error'];
            // $db->close();
            return;
        }

        $logwriter = new LOG_WRITER_CLASS;
        $logwriter->addDataFromTable('anbieter', $anbieterId, 'preparediff');

        if ($oldData['rechtsform'] != $newData['rechtsform']
            || $oldData['gruendungsjahr'] != $newData['gruendungsjahr']
            || $oldData['leitung_name'] != $newData['leitung_name']
            || $oldData['homepage'] != $newData['homepage']
            || $oldData['strasse'] != $newData['strasse']
            || $oldData['plz'] != $newData['plz']
            || $oldData['ort'] != $newData['ort']
            || $oldData['stadtteil'] != $newData['stadtteil']
            || $oldData['anspr_name'] != $newData['anspr_name']
            || $oldData['anspr_zeit'] != $newData['anspr_zeit']
            || $oldData['anspr_tel'] != $newData['anspr_tel']
            || $oldData['anspr_fax'] != $newData['anspr_fax']
            || $oldData['anspr_email'] != $newData['anspr_email']
            || $oldData['pflege_name'] != $newData['pflege_name']
            || $oldData['pflege_tel'] != $newData['pflege_tel']
            || $oldData['pflege_fax'] != $newData['pflege_fax']
            || $oldData['pflege_email'] != $newData['pflege_email']
        ) {
            // update record
            $sql = "UPDATE anbieter SET rechtsform=" . intval($newData['rechtsform']) . ",
									 gruendungsjahr=" . intval($newData['gruendungsjahr']) . ",
									 leitung_name='" . addslashes($newData['leitung_name']) . "',
									 homepage='" . addslashes($newData['homepage']) . "',
									 strasse='" . addslashes($newData['strasse']) . "',
									 plz='" . addslashes($newData['plz']) . "',
									 ort='" . addslashes($newData['ort']) . "',
									 stadtteil='" . addslashes($newData['stadtteil']) . "',
									 anspr_name='" . addslashes($newData['anspr_name']) . "',
									 anspr_zeit='" . addslashes($newData['anspr_zeit']) . "',
									 anspr_tel='" . addslashes($newData['anspr_tel']) . "',
									 anspr_fax='" . addslashes($newData['anspr_fax']) . "',
									 anspr_email='" . addslashes($newData['anspr_email']) . "',
									 pflege_name='" . addslashes($newData['pflege_name']) . "',
									 pflege_tel='" . addslashes($newData['pflege_tel']) . "',
									 pflege_fax='" . addslashes($newData['pflege_fax']) . "',
									 pflege_email='" . addslashes($newData['pflege_email']) . "', ";
            $sql .= " user_modified={$user}, ";    // der Benutzer, wird nur geaendert, wenn etwas im Protokoll steht; dies ist notwendig, da durch die Suche nach dem Benutzer (20) die Redaktion die Aenderungen im Protokoll ueberprueft
            // das Datum muss dagegen auch geaendert werden, wenn nur bei der Promotion etwas geaendert wurde, da ansonsten die Aenderungen nicht "live" geschaltet werden (bzw. nur stark verzoegert)
            $sql .= " date_modified='{$today}' WHERE id=$anbieterId;";
            $db->query($sql);


            // log after the record being written
            $logwriter->addDataFromTable('anbieter', $anbieterId, 'creatediff');
            $logwriter->log('anbieter', $anbieterId, $user, 'edit');
        }
        // $db->close();
    }

    function renderEditAnbieter()
    {
        $anbieterId = intval($_SESSION['loggedInAnbieterId']);
        $topnotes = array();
        $showForm = true;

        // see what to do ...
        if ($_POST['subseq'] == 1 && isset($_POST['cancel'])) {
            // ... a subsequent call: "Cancel" hit
            header('Location: ' . $this->bwd);
            exit();
        } else if ($_POST['subseq'] == 1) {
            // ... save data
            $anbieter = $this->loadAnbieterFromPOST($anbieterId);
            if (sizeof((array)$anbieter['error']) == 0) {
                $this->saveAnbieterToDb($anbieter);
            } /* no else: saveAnbieterToDb() may also add errors */

            if (sizeof((array)$anbieter['error'])) {
                $topnotes = $anbieter['error'];
            } else {
                $msg = 'Das Anbieterprofil wurde <b>erfolgreich gespeichert.</b>';
                setcookie('editmsg', $msg);

                $bwd = $this->bwd . (strpos($this->bwd, '?') === false ? '?' : '&') . 'rnd=' . time();
                header('Location: ' . $bwd);
                exit();
            }
        } else {
            // ... first call
            $anbieter = $this->loadAnbieterFromDb($anbieterId);
            if (sizeof((array)$anbieter['error'])) {
                $topnotes = $anbieter['error'];
                $showForm = false;
            }
        }

        // render form
        echo $this->framework->getPrologue(array('title' => 'Anbieterprofil bearbeiten', 'bodyClass' => 'wisyp_edit'));
        echo '<h1>Anbieterprofil bearbeiten</h1>';

        if (sizeof((array)$topnotes)) {
            echo "<p class=\"wisy_topnote\">" . implode('<br />', $topnotes) . "</p>";
        }

        $secureaction = "";

        if ($this->framework->iniRead('useredit.secure', 1) == 1
            && substr($_SERVER['HTTP_HOST'], -6) != '.local'
            && $_SERVER['HTTPS'] == "on") {
            $secureaction = 'https://' . $_SERVER['HTTP_HOST'] . '/edit';
        }

        $editurl = ($secureaction != "") ? $secureaction : "edit";

        echo '<form action="' . $editurl . '" method="post" name="anbieter">' . "\n";
        echo '<input type="hidden" name="action" value="ea" /> ' . "\n";
        echo '<input type="hidden" name="subseq" value="1" /> ' . "\n";
        echo '<input type="hidden" name="bwd" value="' . htmlspecialchars($this->bwd) . '" /> ' . "\n";

        if ($showForm) {
            echo '<table cellspacing="2" cellpadding="0" width="100%">';

            // Titel, ID
            echo '<tr>';
            echo '<td width="10%">Suchname:</td>';
            echo '<td width="90%">' . htmlspecialchars(cs8($anbieter['suchname'])) . '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Anbieternummer:</td>';
            echo '<td width="90%">' . htmlspecialchars(cs8($anbieter['id'])) . '</td>';
            echo '</tr>';

            // firmenportrait
            echo '<tr>';
            echo '<td width="10%">Rechtsform:</td>';
            echo '<td width="90%">';
            $this->controlSelect('rechtsform', $anbieter['rechtsform'], $GLOBALS['codes_rechtsform']);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Gr&uuml;ndungsjahr:</td>';
            echo '<td width="90%">';
            $ausgabe_jahr = $anbieter['gruendungsjahr'] <= 0 ? '' : $anbieter['gruendungsjahr'];
            $this->controlText('gruendungsjahr', $ausgabe_jahr, 6, 4, '', '', '^[0-9]{4}$', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%" nowrap="nowrap">Name des Leiters:</td>';
            echo '<td width="90%">';
            $this->controlText('leitung_name', $anbieter['leitung_name'], 50, 200, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Homepage:</td>';
            echo '<td width="90%">';
            $this->controlText('homepage', $anbieter['homepage'], 64, 200, '', '', '.*\..*', '', 0); // https?://.+
            echo '</td>';
            echo '</tr>';

            // Adresse
            echo '<tr>';
            echo '<td width="10%">Adresse:</td>';
            echo '<td width="90%">';
            $this->controlText('strasse', $anbieter['strasse'], 25, 100, 'Geben Sie hier - soweit bekannt und eindeutig - die Strasse und die Hausnummer ein', 'Strasse und Hausnr.', '', '', 0);

            echo ' &nbsp; ';

            $this->controlText('plz', $anbieter['plz'], 5, 16, 'Geben Sie hier - soweit bekannt und eindeutig - die Postleitzahl ein', 'PLZ', '', '', 0);
            echo ' ';
            $this->controlText('ort', $anbieter['ort'], 12, 60, 'Geben Sie hier - soweit bekannt und eindeutig - den Ort bzw. die Stadt ein', 'Ort', '', '', 0);

            $this->controlHidden('stadtteil', cs8($anbieter['stadtteil']));

            $this->controlHidden('stadtteil_for', cs8($anbieter['strasse']) . ',' . cs8($anbieter['plz']) . ',' . cs8($anbieter['ort']));
            echo '</td>';
            echo '</tr>';

            // Kundenkontakt
            echo '<tr>';
            echo '<td colspan="2">&nbsp;<br /><strong>Kundenkontakt:</strong> (&ouml;ffentlich im Web sichtbar)</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%" nowrap="nowrap">Name:</td>';
            echo '<td width="90%">';
            $this->controlText('anspr_name', $anbieter['anspr_name'], 50, 50, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Sprechzeiten:</td>';
            echo '<td width="90%">';
            $this->controlText('anspr_zeit', $anbieter['anspr_zeit'], 64, 200, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Telefon:</td>';
            echo '<td width="90%">';
            $this->controlText('anspr_tel', $anbieter['anspr_tel'], 32, 200, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Telefax:</td>';
            echo '<td width="90%">';
            $this->controlText('anspr_fax', $anbieter['anspr_fax'], 32, 200, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">E-Mail:</td>';
            echo '<td width="90%">';
            $this->controlText('anspr_email', $anbieter['anspr_email'], 50, 200, '', '', '[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$', '', 0);
            echo '</td>';
            echo '</tr>';

            // Pflegekontakt
            echo '<tr>';
            echo '<td colspan="2">&nbsp;<br /><strong>Pflegekontakt:</strong> (nur f&uuml;r die interne Datenredaktion)</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%" nowrap="nowrap">Name:</td>';
            echo '<td width="90%">';
            $this->controlText('pflege_name', $anbieter['pflege_name'], 50, 50, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Telefon:</td>';
            echo '<td width="90%">';
            $this->controlText('pflege_tel', $anbieter['pflege_tel'], 32, 200, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">Telefax:</td>';
            echo '<td width="90%">';
            $this->controlText('pflege_fax', $anbieter['pflege_fax'], 32, 200, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td width="10%">E-Mail:</td>';
            echo '<td width="90%">';
            $this->controlText('pflege_email', $anbieter['pflege_email'], 50, 200, '', '', '', '', 0);
            echo '</td>';
            echo '</tr>';

            echo '</table>';
        }

        echo '<p>' . "\n";
        if ($showForm) {
            echo '<input type="submit" value="OK - Anbieterprofil speichern" title="Alle &Auml;nderungen &uuml;bernehmen und Anbieterprofil speichern" style="font-weight: bold;" /> ' . "\n";
        }

        echo '<input type="submit" name="cancel" value="Abbruch" title="&Auml;nderungen verwerfen und Kurs nicht speichern" />' . "\n";
        echo '</p>' . "\n";

        if ($showForm) {
            $a = '';
            $aend = '';
            $email = $this->framework->iniRead('useredit.help.mail.to', '');
            if ($email != '') {
                $a = "<a href=\"mailto:{$email}\">";
                $aend = "</a>";
            }

            echo "<p>&Auml;nderungsbedarf in der Anbieterbeschreibung und weiteren Merkmalen bitte {$a}an die Redaktion mailen{$aend}.</p>";
        }

        echo '</form>' . "\n";

        echo $this->framework->getEpilogue();
    }

    /**************************************************************************
     * 20.09.2013 new AGB stuff
     **************************************************************************/

    private
    function _agb_get_hash()
    {
        $agb_glossar_entry = intval($this->framework->iniRead('useredit.agb', 0));
        if ($agb_glossar_entry <= 0)
            return ''; // no AGB required

        $db = new DB_Admin;
        $db->query("SELECT erklaerung FROM glossar WHERE id=" . $agb_glossar_entry);
        if (!$db->next_record())
            return ''; // AGB record does not exist

        $temp = $db->fcs8('erklaerung');
        if ($temp == '')
            return ''; // AGB are empty

        $temp = strtr($temp, "\n\r\t", "   ");
        $temp = str_replace(' ', '', $temp);
        $hash = md5($temp);

        // $db->close();
        return $hash; // AGB-hash to confirm
    }

    private
    function _dataprotection_get_hash()
    {
        $dataprotection_glossar_entry = intval($this->framework->iniRead('useredit.datenschutz', 0));
        if ($dataprotection_glossar_entry <= 0)
            return '';

        $db = new DB_Admin;
        $db->query("SELECT erklaerung FROM glossar WHERE id=" . $dataprotection_glossar_entry);
        if (!$db->next_record())
            return ''; // DP record does not exist

        $temp = $db->f8('erklaerung');
        if ($temp == '')
            return ''; // DP are empty

        $temp = strtr($temp, "\n\r\t", "   ");
        $temp = str_replace(' ', '', $temp);
        $hash = md5($temp);

        // $db->close();
        return $hash;
    }

    private
    function _agb_reading_required()
    {
        if ($_SESSION['_agb_ok_for_this_session'])
            return false; // AGB were okay at the beginning of the session, keep this state to avoid annoying AGB popups during editing

        $soll_hash = $this->_agb_get_hash() . $this->_dataprotection_get_hash();
        if ($soll_hash == '')
            return false; // no AGB reading required

        if ($this->_anbieter_ini_read('useredit.agb.accepted_hash', '') == $soll_hash)
            return false; // AGB already read

        if (isset($_REQUEST['agb_not_accepted'])) {
            header('Location: ' . $this->framework->getUrl('edit', array('action' => 'logout')));
            exit();
        } else if (isset($_REQUEST['agb_accepted'])
            && $soll_hash == $_REQUEST['agb_hash']) {
            if (!$_SESSION['_login_as']) {
                $this->_anbieter_ini_settings['useredit.agb.accepted_hash'] = $soll_hash;
                $this->_anbieter_ini_write();
                $logwriter = new LOG_WRITER_CLASS;
                $logwriter->log('anbieter', intval($_SESSION['loggedInAnbieterId']), $this->getAdminAnbieterUserId20(), 'agbaccepted');

                $db = new DB_Admin;
                $today = strftime("%d.%m.%y");
                $db->query("UPDATE anbieter SET notizen = CONCAT('$today: AGB akzeptiert\n', notizen) WHERE id=" . intval($_SESSION['loggedInAnbieterId']));
                // $db->close();
            }

            $_SESSION['_agb_ok_for_this_session'] = true;
            header("Location: " . $_REQUEST['fwd']);
            // $db->close();
            exit();
        }

        return true; // AGB reading required!
    }

    private
    function _render_agb_screen()
    {
        $agb_glossar_entry = intval($this->framework->iniRead('useredit.agb', 0));
        $db = new DB_Admin;
        $db->query("SELECT begriff, erklaerung FROM glossar WHERE id=" . $agb_glossar_entry);
        $db->next_record();
        $begriff = $db->fcs8('begriff');
        $erklaerung = $db->fcs8('erklaerung');

        $dataprotection_glossar_entry = intval($this->framework->iniRead('useredit.datenschutz', 0));
        if ($dataprotection_glossar_entry > 0) {
            $db = new DB_Admin;
            $db->query("SELECT begriff, erklaerung FROM glossar WHERE id=" . $dataprotection_glossar_entry);
            $db->next_record();
            $begriff_dataprotection = $db->f8('begriff');
            $erklaerung_dataprotection = $db->f8('erklaerung');
        }

        echo $this->framework->getPrologue(array('title' => $begriff, 'bodyClass' => 'wisyp_edit'));

        echo '<h1>Zur Bearbeitung Ihrer Daten ist Ihre Zustimmung zu den AGB' . ($erklaerung_dataprotection ? ' und der Datenschutzerkl&auml;rung' : '') . ' n&ouml;tig.</h1>';
        echo '<h2>Grund: &Auml;nderung seit Ihrem letzten Login oder Ihr erster Login.</h2>';
        echo '<br><br><br>';

        echo '<a name="top"></a>'; // make [[toplinks()]] work
        echo '<h1>' . htmlspecialchars($begriff) . '</h1>';
        $wiki2html =& createWisyObject('WISY_WIKI2HTML_CLASS', $this->framework);
        $wiki2html->forceBlankTarget = true;
        echo $wiki2html->run($erklaerung);

        if ($dataprotection_glossar_entry > 0 && $erklaerung_dataprotection) {
            echo '<hr><hr>';
            echo '<h1>' . htmlspecialchars($begriff_dataprotection) . '</h1><br><br>';
            $wiki2html =& createWisyObject('WISY_WIKI2HTML_CLASS', $this->framework);
            $wiki2html->forceBlankTarget = true;
            echo $wiki2html->run($erklaerung_dataprotection);
        }

        $fwd = 'search';
        if ($_REQUEST['action'] == 'ek') {
            $fwd = "edit?action=ek&id=" . intval($_REQUEST['id']);
        } else if (isset($_REQUEST['fwd'])) {
            $fwd = $_REQUEST['fwd'];
        }

        echo '<br><br><form action="edit" method="post">';
        echo '<input type="hidden" name="fwd" value="' . htmlspecialchars($fwd) . '" />';
        echo '<input type="hidden" name="agb_hash" value="' . htmlspecialchars($this->_agb_get_hash()) . htmlspecialchars($this->_dataprotection_get_hash()) . '" />';
        echo 'Ich habe die AGB ' . ($erklaerung_dataprotection ? 'und die Datenschutzerkl&auml;rung ' : '') . 'gelesen:<br>';
        echo '<input type="submit" name="agb_accepted" value="OK - Ich stimme den AGB' . ($erklaerung_dataprotection ? 'und der Datenschutzerkl&auml;rung ZU' : '') . '" style="font-weight: bold; font-size: 1em;"/>';
        echo ' &nbsp; &nbsp; ';
        echo '<input type="submit" name="agb_not_accepted" value="Abbruch - Ich stimme einigen Bedingungen NICHT ZU"  style="font-weight: bold; font-size: 1em;"/>';
        echo '</form><br><br>';

        if ($_SESSION['_login_as']) {
            echo '<p style="background:red; color:white; padding:1em; "><b>Achtung:</b> Sie haben sich als Redakteur im Namen eines Anbieters,
					der die AGB noch nicht best&auml;tigt hat, eingeloggt. Wenn Sie die AGB jetzt best&auml;tigen, gilt dies nur f&uuml;r die aktuelle Sitzung;
					der Anbieter wird die AGB sobald er sich selbst einloggt erneut best&auml;tigen m&uuml;ssen. Dieser Hinweis erscheint nur f&uuml;r Redakteure.</p>';

        }

        // $db->close();
        echo $this->framework->getEpilogue();
    }

    /**************************************************************************
     * edit main() - see what to do
     **************************************************************************/

    function render()
    {
        $action = $_REQUEST['action'];

        if ($action == 'forgotpw') {
            $ob =& createWisyObject('WISY_EDIT_FORGOTPW_CLASS', $this->framework, array('adminAnbieterUserId' => $this->getAdminAnbieterUserId20()));
            $ob->renderForgotPwScreen();
        } else if ($this->framework->getEditAnbieterId() <= 0) {
            $this->renderLoginScreen();
        } else if ($action == 'logout') {
            $this->renderLogoutScreen();
        } else if ($this->_agb_reading_required()) {
            $this->_render_agb_screen();
        } else if ($action == 'kategorie') {
            $this->handleCheckboxChange();
        } else if ($action == 'hauptthema') {
            $this->handleThemaChange();
        } else if ($action == 'kursniveaustufe') {
            $this->handleKursniveaustufe();
        } else if ($action == 'generateescokompentenz') {
            $this->generateEscoCompetence();
        } else if ($action == 'courseSkills') {
            $this->courseSkills();
        } else {
            // these are the normal edit actions, for these actions the AGB must be accepted!

            $_SESSION['_agb_ok_for_this_session'] = true; // only check the AGB once a session - otherwise it may happend that during editing new AGB occur!
            switch ($action) {
                case 'loginSubseq': // diese Bedingung sollte eigentlich komplett von renderLoginScreen() oben augehandet sein ... aber es schadet auch nichts ...
                    $this->renderLoginScreen();
                    break;

                case 'ek':
                    $this->renderEditKurs(intval($_REQUEST['id']) /*may be "0" for "new kurs"*/);
                    break;

                case 'ea':
                    $this->renderEditAnbieter(); // always edit the "logged in" anbieter
                    break;

                /* case 'kt':
                    $this->renderEditKonto();
                    break; */

                default:
                    $this->framework->error404();
                    break;
            }
        }
    }

}

;
