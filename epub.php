<?php
/*
 *
 * MYSQL To ePub Converter
 *
 *
 * Copyright © Jan Schär, 2010, www.janschaer.ch
 *
 *
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 *
 * Common Problems:
 * -Encoding
 *   There are several encodin steps, because my content was UTF8-encoded.
 *   So I decode them; this happens exclusively in the following two functions:
 *   function theme_paragraph()
 *   function replaceVars()
 *
 *
 *
 */


/* ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++ */
/* Config                                                                                                           */
/* ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++ */


// The Title of the File that is sent to browser
$book_output_title  = 'Das_Buch_Mormon_'.date('YmdHis');

$book_id            = 'janschaer.ch210502012011'; // muss eindeutig sein (zB domain + date/time)
$book_author        = 'Kirche Jesu Christi der Heiligen der Letzten Tage';
$book_publisher     = 'Jan Schaer';
$book_language      = 'de'; // get from http://www.loc.gov/standards/iso639-2/php/code_list.php

// Titel des Buches
$book_title         = 'Das Buch Mormon';
$book_subtitle      = 'Ein weiterer Zeuge fuer Jesus Christus';

$book_description   = 'ein sehr tolles Buch';

// MYSQL Connection
$username = "";
$password = "";
$hostname = "localhost";
$database = "";

// Pfad für Tmpordner (muss Schreibrechte 777 haben); mit abschliessendem '/'
$tmp      = "/home/www/web108/html/intranet/buchmormon/tmp/";

/*
 * SQL TO get the Book
 * must have the following output-structure
 * -title             the title of the chapter
 *   - subchapter     the subchapter
 *     - paragraph    the paragraph number
 *       - content    the content of that paragraph
 */

$sql = "SELECT book_title as title, chapter as subchapter, verse as paragraph, verse_scripture as content
        FROM lds_scriptures_verses_de LEFT JOIN lds_scriptures_books_de
        ON (lds_scriptures_verses_de.book_id = lds_scriptures_books_de.book_id)
        ORDER BY lds_scriptures_verses_de.verse_id";
/* Example
$sql = "SELECT book_title as title, chapter as subchapter, verse as paragraph, verse_content as content
        FROM thebooktable";
*/


// Die Texte vor den jeweiligen Navigationspunkten
$nav_labels = array(
     'chapter'    => '',
     'subchapter' => 'Kapitel ',
     'paragraph'  => ''
);



















/* ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++ */
/* Don't edit below here ( until you want to change something in the Program itself )                               */
/* ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++ */

// Constants
define('TMP', $tmp.time().'.zip');

// Connection to Database
$dbhandle = mysql_connect($hostname, $username, $password) or die("Unable to connect to MySQL");
$selected = mysql_select_db($database,$dbhandle) or die("Could not select ".$database);

// Create ePub Ebook
createArchive();
createContent(getBook());
epubOutput($book_output_title);





/* ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++ */
/* Functions                                                                                                        */
/* ++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++ */

/*
 * retrieves a full Book from the DB
 */
function getBook() {
  global $sql;
  $book = array();
  $results = mysql_query($sql);
  while($r = mysql_fetch_object($results)) {
    $book[$r->title][$r->subchapter][$r->paragraph] = array('content'=>$r->content);
  }
  return $book;
}

/*
 * Creates the dynamic files
 *
 * The content.opf File contains all the Ref-Items
 * toc.ncx contains the Table of Contents (also Hierarchical)
 * page.xhtml contains one subchapter per file
 */
function createContent($book) {
    global $nav_labels;
    $manifest = $spines = $navpoints = array();
    $i = 1; // This Counter counts thorugh all chapters/subchapters/paragraphs
    $s = 3; // This Counter counts thorugh all chapters
    foreach ($book as $chapterid=>$chapter) {
      $navpoints[$chapterid] = theme_navpoint($i,$s,$nav_labels['chapter'].$chapterid,$i+1);
      $i++;
      foreach ($chapter as $subchapterid=>$subchapter) {
        $paragraphs = array();
        $navpoints[$chapterid] .= theme_navpoint($i,$s,$nav_labels['subchapter'].$subchapterid,$i);
        $manifest[] = '<item id="c'.$i.'" href="'.$i.'.xhtml" media-type="application/xhtml+xml" />';
        $spines[]   = '<itemref idref="c'.$i.'" />';
        $subchp_i = $i;
        $i++;
        $s++;
        foreach ($subchapter as $paragraphId=>$paragraph) {
          $paragraphs[] = theme_paragraph($paragraphId, $paragraph['content']);
          $i++;
        }
        $navpoints[$chapterid] .= '</navPoint>';
        epubAddfile($subchp_i.'.xhtml', str_replace(array('%title%','%content%'), array($chapterid.' '.$subchapterid,implode($paragraphs)), getPageXhtml()));
      }
      $navpoints[$chapterid] .= '</navPoint>';
    }
    epubAddfile('content.opf',str_replace(array('%chapters%', '%spines%'), array(implode($manifest), implode($spines)), replaceVars(file_get_contents('templates/content.opf'))));
    epubAddfile('toc.ncx', str_replace('%navpoints%',implode($navpoints),replaceVars(file_get_contents('templates/toc.ncx'))));
}

/*
 * Gets the content of the page.xhtml
 * to prevent php from loading it for every subchapter,
 * we cache the whole content
 */
function getPageXhtml() {
  static $page = false;
  if ($page) return $page;
  $page = replaceVars(file_get_contents('templates/page.xhtml'));
  return $page;
}


/*
 * Themes a single Paragraph
 */
function theme_paragraph($no, $content) {
  $nr       = '<div class="sup" id="p_'.$no.'">'.$no.'</div>';
  $content  = '<div class="paragraph" id="p_'.$no.'_content">'.$content.'</div>';
  return $nr.$content;
}

/*
 * Themes a navpoint
 * Attention: the closing tag is missed here; I has to be added manualy
 * after calling this function
 */
function theme_navpoint($id,$playorder,$label,$src) {
  return
  '<navPoint id="c'.$id.'" playOrder="'.$playorder.'">'.
    '<navLabel><text>'.$label.'</text></navLabel>'.
    '<content src="'.$src.'.xhtml" />';
}

/*
 * Replaces Variables in Template files with Globals
 */
function replaceVars($content) {
  global $book_title, $book_subtitle, $book_id, $book_author, $book_publisher, $book_language, $book_description;
  $vars = array('%booktitle%','%booksubtitle%','%bookid%','%bookpublisher%','%bookauthor%','%booklanguage%','%version%'      ,'%book_descriptionHTML%','%booktitleHTML%','%booksubtitleHTML%');
  $repl = array( $book_title , $book_subtitle , $book_id , $book_publisher , $book_author , $book_language ,date('Y-m-d H:i'), $book_description      , $book_title     , $book_subtitle);
  return str_replace($vars, $repl, $content);
}


/*
 * Creates an empty Epub Ebook
 * from Template files
 */
function createArchive() {
  if (file_exists(TMP)) unlink(TMP);
  copy ("templates/epub.zip", TMP);
  $zip = new ZipArchive();
  $zip->open         (TMP, ZIPARCHIVE::CREATE);
  $zip->addEmptyDir  ('META-INF');
  $zip->addEmptyDir  ('OEBPS');
  $zip->addEmptyDir  ('OEBPS/images');
  $zip->addFromString('OEBPS/title_page.xhtml', replaceVars(file_get_contents("templates/title_page.xhtml")));
  $zip->addFromString('OEBPS/stylesheet.css'  , file_get_contents("templates/stylesheet.css"));
  $zip->addFromString('OEBPS/cover.xhtml'     , file_get_contents("templates/cover.xhtml"));
  $zip->addFromString('OEBPS/images/cover.png', file_get_contents("templates/cover.png"));
  $zip->addFromString('META-INF/container.xml', file_get_contents("templates/container.xml"));
  $zip->close();
}

/*
 * Adds a File to ePub eBook
 */
function epubAddfile($name, $content, $path='OEBPS') {
  $zip = new ZipArchive();
  $zip->open(TMP, ZIPARCHIVE::CREATE);
  $zip->addFromString($path.'/'.$name, $content);
  $zip->close();
}

/*
 * Outputs the ePub eBook
 */
function epubOutput($filename='mybook') {
  header('Content-type: application/epub+zip');
  header('Content-disposition:attachment;filename="'.$filename.'.epub"');
  header('Content-Transfer-Encoding: binary');
  readfile(TMP);
  if (file_exists(TMP)) unlink(TMP);
}

?>