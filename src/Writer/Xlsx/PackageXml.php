<?php

declare(strict_types=1);

namespace SpreadsheetWriter\Writer\Xlsx;

use SpreadsheetWriter\Internal\Xml;
use SpreadsheetWriter\Sheet;

/**
 * Builds the package parts of an XLSX file that tie the workbook together:
 * content types, relationships and the workbook part.
 *
 * Relationship ids of xl/workbook.xml: rId1 is the sheet,
 * rId2 the style table and rId3 the shared string table.
 *
 * @internal
 */
final class PackageXml
{
    /**
     * namespace of the package relationships
     */
    private const string NS_PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * base of the relationship types of office documents
     */
    private const string RELATIONSHIP_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    /**
     * base of the content types of SpreadsheetML parts
     */
    private const string CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';

    /**
     * Return the XML of [Content_Types].xml.
     *
     * @return string
     */
    public static function buildContentTypes(): string
    {
        $overrides = '<Override PartName="/xl/workbook.xml" ContentType="'.self::CONTENT_TYPE.'sheet.main+xml"/>';
        $overrides .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="'.self::CONTENT_TYPE.'worksheet+xml"/>';
        $overrides .= '<Override PartName="/xl/styles.xml" ContentType="'.self::CONTENT_TYPE.'styles+xml"/>';
        $overrides .= '<Override PartName="/xl/sharedStrings.xml" ContentType="'.self::CONTENT_TYPE.'sharedStrings+xml"/>';

        return Xml::DECLARATION
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .$overrides
            .'</Types>';
    }

    /**
     * Return the XML of _rels/.rels, which points to the workbook part.
     *
     * @return string
     */
    public static function buildRootRelationships(): string
    {
        return Xml::DECLARATION
            .'<Relationships xmlns="'.self::NS_PACKAGE_RELATIONSHIPS.'">'
            .'<Relationship Id="rId1" Type="'.self::RELATIONSHIP_TYPE.'officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /**
     * Return the XML of xl/_rels/workbook.xml.rels, which points to the sheet, styles and shared strings.
     *
     * @return string
     */
    public static function buildWorkbookRelationships(): string
    {
        return Xml::DECLARATION
            .'<Relationships xmlns="'.self::NS_PACKAGE_RELATIONSHIPS.'">'
            .'<Relationship Id="rId1" Type="'.self::RELATIONSHIP_TYPE.'worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::RELATIONSHIP_TYPE.'styles" Target="styles.xml"/>'
            .'<Relationship Id="rId3" Type="'.self::RELATIONSHIP_TYPE.'sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>';
    }

    /**
     * Return the XML of xl/workbook.xml with the sheet.
     *
     * @return string
     */
    public static function buildWorkbook(): string
    {
        return Xml::DECLARATION
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView activeTab="0"/></bookViews>'
            .'<sheets><sheet name="'.Xml::escape(Sheet::TITLE).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }
}
