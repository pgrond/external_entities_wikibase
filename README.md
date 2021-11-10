# Storage client for Wikibase source for External entities

This module provides a storage client for exposing external entities in a Wikibase installation to Drupal. See the [external entities documentaion](https://www.drupal.org/docs/contributed-modules/external-entities) for more information.

## Listing

The listing of id's can be retrieved by a SPARQL query. The query should be in the form of:

        SELECT %item WHERE {
            %item *predicate* *object*
        }

The query should return a list of al the QID's of interest. The `%item` variable should is fixed and should always be called `%item`.

It is possible to extend the query to filter out specific objects. The query is transformed with some regular expressions for pagination and interoperability with the Search API module, so highly complex queries may break.

## Detail view

The detail view of an object is retrieved through the REST API of the Wikibase installation. 
