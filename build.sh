#!/bin/sh

#TO=$(pwd)/cli-tools ./update-cli-tools.sh
docker run -u$(id -u):$(id -g) -ti --rm -v $(pwd):/work -eMODE=production php:7.3-cli-alpine -f /work/build.php

# Loop through each folder in the 'build' directory
for dir in build/*-fpm; do
  tag=$(basename $dir)
  docker build --no-cache -t konstack/php:$tag -f build/$tag/Dockerfile .
done

# Loop through each folder in the 'build' directory
for dir in build/*-cli; do
  tag=$(basename $dir)
  docker build --no-cache -t konstack/php:$tag -f build/$tag/Dockerfile .
done

docker push -a konstack/php
