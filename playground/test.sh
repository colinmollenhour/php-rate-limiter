#!/bin/bash

algorithm=$ALGORITHM
concurrent=${CONCURRENT:-20}
burst=${BURST:-100}
rate=${RATE:-8}
sleep=${SLEEP:-0.4}

hey -n ${REQUESTS:-500} -c ${REQ_CONC:-20} \
       "http://localhost:8080/?algorithm=$algorithm&key=colin&concurrent=$concurrent&burst=$burst&rate=$rate&sleep=$sleep&timeout=30&format=json" &
sleep 0.2 && curl \
       "http://localhost:8080/?algorithm=$algorithm&key=colin&concurrent=$concurrent&burst=$burst&rate=$rate&sleep=$sleep&timeout=30&format=json" -v
