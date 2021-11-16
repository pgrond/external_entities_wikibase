# Storage client for Wikibase source for External entities

This module provides a storage client for exposing external entities in a Wikibase installation to Drupal. See the [external entities documentation](https://www.drupal.org/docs/contributed-modules/external-entities) for more information.

## Listing

The listing of id's can be retrieved by a SPARQL query. The query should be in the form of:

        SELECT %item WHERE {
            %item *predicate* *object*
        }

The query should return a list of al the QID's of interest. The `%item` variable should is fixed and should always be called `%item`.

It is possible to extend the query to filter out specific objects. The query is transformed with some regular expressions for pagination and interoperability with the Search API module, so highly complex queries may break.

## Detail view

The detail view of an object is retrieved through the REST API of the Wikibase installation.

## Search API

Views can not handle external entities at the moment. See https://www.drupal.org/project/external_entities/issues/2538706.

To make dynamic, flexible listings you can use Search API to index the external entities. This submodule has some functionality to keep the search index in sync with upstream changes:

- Get the latest updates from Wikibase since last index and mark updated entities for reindexing
- Changing the search query will trigger a rebuild of the tracking info because this can narrow or widen the result set.
