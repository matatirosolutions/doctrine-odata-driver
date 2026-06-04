<?php
declare(strict_types=1);

namespace Matatirosoln\DoctrineOdataDriver\Metadata;

use Matatirosoln\DoctrineOdataDriver\Exception\ODataDriverException;

/**
 * Parses OData EDMX (Entity Data Model XML) documents into a structured array.
 *
 * EDMX is the XML format used by OData v4's $metadata endpoint. All OData v4
 * servers expose this endpoint; the JSON alternative (CSDL JSON) is an OData
 * v4.01 addition. XML is used here for maximum server compatibility.
 *
 * Uses DOMDocument::getElementsByTagNameNS() rather than SimpleXML XPath
 * because SimpleXML's namespace prefix registration is unreliable with the
 * mixed default/prefixed namespace structure of EDMX documents.
 * getElementsByTagNameNS() matches on the actual namespace URI regardless of
 * whatever prefix the server chose, making it robust across implementations.
 */
class EdmxParser
{
    private const string EDM_NAMESPACE = 'http://docs.oasis-open.org/odata/ns/edm';

    /**
     * Parses raw EDMX XML into a map of entity-set name → metadata.
     *
     * Returns:
     * [
     *   'User' => [
     *     'pk'         => '__pk_UserID',
     *     'properties' => [
     *       '__pk_UserID' => ['type' => 'Edm.String', 'nullable' => false],
     *       'Name'        => ['type' => 'Edm.String', 'nullable' => true],
     *     ],
     *   ],
     *   ...
     * ]
     *
     * @return array<string, array{pk: string, properties: array<string, array{type: string, nullable: bool}>}>
     * @throws ODataDriverException
     */
    public function parse(string $xml): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);

        if (!$dom->loadXML($xml)) {
            $error = libxml_get_last_error();
            throw new ODataDriverException(
                'Failed to parse OData $metadata XML: ' . ($error ? trim($error->message) : 'unknown error'),
            );
        }

        $ns = self::EDM_NAMESPACE;

        // Build a map of EntityType local name → [pk, properties]
        $entityTypes = [];

        foreach ($dom->getElementsByTagNameNS($ns, 'EntityType') as $entityType) {
            $typeName   = $entityType->getAttribute('Name');
            $properties = [];

            foreach ($entityType->getElementsByTagNameNS($ns, 'Property') as $property) {
                $properties[$property->getAttribute('Name')] = [
                    'type'     => $property->getAttribute('Type') ?: 'Edm.String',
                    'nullable' => strtolower($property->getAttribute('Nullable') ?: 'true') !== 'false',
                ];
            }

            // The primary key is the first PropertyRef inside the Key element
            $pk      = '';
            $keyRefs = $entityType->getElementsByTagNameNS($ns, 'PropertyRef');
            if ($keyRefs->length > 0) {
                $pk = $keyRefs->item(0)->getAttribute('Name');
            }

            $entityTypes[$typeName] = ['pk' => $pk, 'properties' => $properties];
        }

        // Map EntitySet names (the OData URL segment) to their EntityType definitions.
        // The EntityType attribute is namespace-qualified ("Namespace.TypeName") so
        // we strip the namespace prefix to get the local type name for the lookup.
        $result = [];

        foreach ($dom->getElementsByTagNameNS($ns, 'EntitySet') as $entitySet) {
            $setName       = $entitySet->getAttribute('Name');
            $qualifiedType = $entitySet->getAttribute('EntityType');
            $localTypeName = str_contains($qualifiedType, '.')
                ? substr($qualifiedType, strrpos($qualifiedType, '.') + 1)
                : $qualifiedType;

            if (isset($entityTypes[$localTypeName])) {
                $result[$setName] = $entityTypes[$localTypeName];
            }
        }

        return $result;
    }
}
