<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Ods;

use SpreadsheetWriter\Internal\DefaultFont;
use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\Sheet;

/**
 * Builds the parts of an ODS file: manifest, meta data, settings, common styles and the content frame.
 *
 * @internal
 */
final class PackageXml
{
    /**
     * ODF version written to all parts
     */
    public const string VERSION = '1.2';

    /**
     * namespace of the office elements
     */
    private const string NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    /**
     * namespace of the prefix 'ooo' in the names of the settings
     *
     * LibreOffice ignores the item sets 'ooo:view-settings' and 'ooo:configuration-settings', and with
     * them the gridlines, when the prefix is not declared.
     */
    private const string NS_OOO = 'http://openoffice.org/2004/office';

    /**
     * namespace declarations of the root elements of content.xml and styles.xml
     *
     * Both declare the same namespaces, as LibreOffice does, because PhpSpreadsheet's ODS reader
     * looks up the namespaces of content.xml in styles.xml.
     */
    private const string DOCUMENT_NAMESPACES = ' xmlns:office="'.self::NS_OFFICE.'"'
        .' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
        .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        .' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        .' xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"'
        .' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
        .' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"';

    /**
     * name of the common cell style that all automatic cell styles inherit from
     */
    public const string DEFAULT_CELL_STYLE = 'Default';

    /**
     * Return the XML of META-INF/manifest.xml, which lists the parts of the package.
     *
     * @param string $mimeType MIME type of the document
     *
     * @return string
     */
    public static function buildManifest(string $mimeType): string
    {
        $entries = '<manifest:file-entry manifest:full-path="/" manifest:version="'.self::VERSION.'" manifest:media-type="'.$mimeType.'"/>';
        foreach (['content.xml', 'styles.xml', 'meta.xml', 'settings.xml'] as $part) {
            $entries .= '<manifest:file-entry manifest:full-path="'.$part.'" manifest:media-type="text/xml"/>';
        }

        return Xml::DECLARATION
            .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="'.self::VERSION.'">'
            .$entries
            .'</manifest:manifest>';
    }

    /**
     * Return the XML of meta.xml.
     *
     * @return string
     */
    public static function buildMeta(): string
    {
        return Xml::DECLARATION
            .'<office:document-meta xmlns:office="'.self::NS_OFFICE.'"'
            .' xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" office:version="'.self::VERSION.'">'
            .'<office:meta><meta:generator>SpreadsheetWriter</meta:generator></office:meta>'
            .'</office:document-meta>';
    }

    /**
     * Return the XML of settings.xml with the view settings: the active sheet and its gridlines.
     *
     * LibreOffice reads the gridlines of a sheet from the 'Tables' map of the view settings. The view-wide
     * and the document-wide 'ShowGrid' items have the same value: the document-wide item is
     * used when there is no view, e.g. when LibreOffice converts the file in headless mode.
     * The root element declares the namespace of the prefix 'ooo', see {@see self::NS_OOO}.
     *
     * @param Sheet $sheet the sheet
     *
     * @return string
     */
    public static function buildSettings(Sheet $sheet): string
    {
        $title = Xml::escape(Sheet::TITLE);
        $showGrid = self::createConfigItem('ShowGrid', 'boolean', $sheet->areGridlinesShown() ? 'true' : 'false');
        $tables = '<config:config-item-map-entry config:name="'.$title.'">'.$showGrid.'</config:config-item-map-entry>';

        return Xml::DECLARATION
            .'<office:document-settings xmlns:office="'.self::NS_OFFICE.'"'
            .' xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0"'
            .' xmlns:ooo="'.self::NS_OOO.'" office:version="'.self::VERSION.'">'
            .'<office:settings><config:config-item-set config:name="ooo:view-settings">'
            .'<config:config-item-map-indexed config:name="Views"><config:config-item-map-entry>'
            .self::createConfigItem('ViewId', 'string', 'view1')
            .'<config:config-item-map-named config:name="Tables">'.$tables.'</config:config-item-map-named>'
            .self::createConfigItem('ActiveTable', 'string', $title)
            .$showGrid
            .'</config:config-item-map-entry></config:config-item-map-indexed>'
            .'</config:config-item-set>'
            .'<config:config-item-set config:name="ooo:configuration-settings">'
            .$showGrid
            .'</config:config-item-set></office:settings>'
            .'</office:document-settings>';
    }

    /**
     * Return the XML of styles.xml with the default font and the common cell style 'Default'.
     *
     * @return string
     */
    public static function buildStyles(): string
    {
        $size = DefaultFont::SIZE.'pt';

        return Xml::DECLARATION
            .'<office:document-styles'.self::DOCUMENT_NAMESPACES.' office:version="'.self::VERSION.'">'
            .'<office:font-face-decls>'
            .'<style:font-face style:name="'.DefaultFont::NAME.'" svg:font-family="'.DefaultFont::NAME.'" style:font-family-generic="swiss"/>'
            .'</office:font-face-decls>'
            .'<office:styles>'
            .'<style:default-style style:family="table-cell">'
            .'<style:text-properties style:font-name="'.DefaultFont::NAME.'" fo:font-size="'.$size.'"'
            .' style:font-size-asian="'.$size.'" style:font-size-complex="'.$size.'"/>'
            .'</style:default-style>'
            .'<style:style style:name="'.self::DEFAULT_CELL_STYLE.'" style:family="table-cell"/>'
            .'</office:styles>'
            .'</office:document-styles>';
    }

    /**
     * Return the XML of content.xml.
     *
     * @param string $automaticStyles the <office:automatic-styles> element
     * @param string $table the <table:table> element of the sheet
     *
     * @return string
     */
    public static function buildContent(string $automaticStyles, string $table): string
    {
        return Xml::DECLARATION
            .'<office:document-content'.self::DOCUMENT_NAMESPACES.' office:version="'.self::VERSION.'">'
            .$automaticStyles
            .'<office:body><office:spreadsheet>'.$table.'</office:spreadsheet></office:body>'
            .'</office:document-content>';
    }

    /**
     * Return a <config:config-item> element.
     *
     * @param string $name name of the setting
     * @param string $type type of the setting, e.g. 'boolean'
     * @param string $value the value, already escaped
     *
     * @return string
     */
    private static function createConfigItem(string $name, string $type, string $value): string
    {
        return '<config:config-item config:name="'.$name.'" config:type="'.$type.'">'.$value.'</config:config-item>';
    }
}
