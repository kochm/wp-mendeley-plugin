<?php
/*
Template Name: BibExhibit
*/
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head profile="http://gmpg.org/xfn/11">
<?php  
  include (TEMPLATEPATH . '/header1.php');
?>
<script src="http://api.simile-widgets.org/exhibit/3.1.1/exhibit-api.js" type="text/javascript"></script>
<script src="http://api.simile-widgets.org/exhibit/3.1.1/extensions/time/time-extension.js" type="text/javascript"></script>
<link href="/index.php?mendeley_action=export-json&id=9e970750-c839-322d-9f31-e8c6814bffe9&type=groups" type="application/json" rel="exhibit/data" />
<style>
div.publication { margin-bottom: 1em; padding: 1em; }
div.author {}
div.title { font-weight: bold; }
div.url { overflow: hidden; width: 500px; }
#middle{
  width: 750px;
  float: left;
  margin: 0 0 0 10px;
  padding: 0 10px 0 0;
  background-color: #FFFFFF;
  border-bottom: 15px solid #cccccc;
}
* html #middle{
  width: 748px !important;
}
span.exhibit-viewPanel-viewSelection-selectedView {
  font-weight:    bold;
  border-bottom:  3px solid #FF9900;
}
#content img{
  padding: 0px;
  background: none;
  border: none;
}
</style>
</head>
<body>
<?php
  include (TEMPLATEPATH . '/header2.php');
?>

<div id="container">
<div id="left">
<?php
  include (TEMPLATEPATH . '/sidebar-page.php');
?>
</div>
  <div id="middle">

<div id="content">
<h1>CSCM Bibliographie</h1>
<p></p>
<table width="100%">
<tr valign="top">
<td ex:role="viewPanel" style="overflow: hidden;">
  <div ex:role="collection" ex:itemTypes="Publication"></div>
  <span ex:control="copy-button" style="float: right"></span>
  <div ex:role="view"
    ex:orders=".year" 
    ex:directions="descending"
    ex:possibleOrders=".pub-type, .author, .year, .label"></div>
  <table ex:role="lens" class="publication" style="display: none">
  <tr>
  <td valign="top">
  <a ex:href-content="value"><div class="title"><span ex:content=".label"></span></div></a>  
  <div class="authors"><span ex:content=".author"></span></div>
  <div class="authors">(<span ex:content=".pub-type"></span>) <span ex:content=".booktitle"></span><span ex:content=".journal"
></span><i ex:if-exists=".editor"> (Hrsg: <span ex:content=".editor"></span>)</i><i ex:if-exists=".pages">, S. <span ex:content=".pages"></span></i></div>
  <div class="url"><span ex:content=".url"></span></div>
  </td>
  </tr>
  </table>
 <div ex:role="view"
     ex:viewClass="Timeline"
     ex:start=".year"
     ex:topBandHeight="90"
     ex:bottomBandHeight="10"
     ex:topBandUnit="year"
     ex:topBandPixelsPerUnit="400"
     ex:colorKey=".pub-type">
 </div>
</td>
<td width="25%" valign="top">
<div ex:role="facet" ex:facetClass="TextSearch" ex:facetLabel="Search"></div>
<div ex:role="facet" ex:expression=".pub-type" ex:facetLabel="Typ" ex:showMissing="false"></div>
<div ex:role="facet" ex:expression=".author" ex:showMissing="false" ex:facetLabel="Autoren"></div>
<div ex:role="facet" ex:expression=".year" ex:showMissing="false" ex:facetLabel="Jahr"></div>
<div ex:role="facet" ex:expression=".tags" ex:facetLabel="Tag" ex:facetClass="Cloud" style="font-size: 80%; text-align:justify"></div>
</td>
</tr>
</table>

</div>
</div>

<?php get_footer(); ?>
