# CP

Implementation of the Concurrent Paths (CP) Algorithm.

You can start the algorithm with 
```cmd
php process.php <folder/filepath> [dot] [json] [without] [output-csv-file]
```
The parameters are the following:
- ```<folder/filepath>```: A relative path (starting in the current directory) to a single PNML or JSON file or a folder where the files are searched.
- ```[dot]```: Optional parameter that creates DOT representations of each decomposition step.
- ```[json]```: Optional parameter to tell the algorithm to search for JSON files.
- ```[without]```: Optional parameter that tells the algorithm to be not verbose and just create a CSV file with the experimental results.
- ```[output-csv-file]```: Optional parameter to specify the name of the output CSV file.

Files in PNML follow the Petri Net Markup Language standard.

Files in JSON have the following simple scheme:
```json
{
    "nodes": [
        {
            "id": <id>,
            "name": "<name>",
            "type": "EVENT" // "START", "END"
        },
        // ...
        {
            "id": <id>,
            "name": "<name>",
            "type": "OR", // "AND", "XOR"
            "gateway": "JOIN" // SPLIT
        },
        // ...
    ],
    "edges": [
        {
            "from": <node-id>,
            "to": <node-id>
        },
        {
            "from": <node-id>,
            "to": <node-id>
        },
        // ...
    ]
```
