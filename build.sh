#!/bin/sh

TO=$(pwd)/cli-tools ./update-cli-tools.sh
docker run -u$(id -u):$(id -g) -ti --rm -v $(pwd):/work -eMODE=production php:7.3-cli-alpine -f /work/build.php

# Loop through each folder in the 'build' directory
for dir in build/*; do
  tag=$(basename $dir)
  docker build -t admibox/php:$tag -f build/$tag/Dockerfile .
done

# docker push -a admibox/php
