## How to install 

##  Get repository from: https://github.com/pult3r/bluebinary-new  :
<br/>
$ gh repo clone pult3r/bluebinary-new <br/>
or<br/>
$ git clone https://github.com/pult3r/bluebinary-new.git<br/>

## Go to project directory :<br/>
$ cd bluebinary-new 

## Install composer :<br/>
$ composer install

## Create cocker container 
For production : docker-compose -f docker-compose-prod.yml up --build
For development docker-compose -f docker-compose-dev.yml up --build


## Go to docker container : 
For production docker exec -it bluebinary-app-prod bash -l
For development : docker exec -it bluebinary-app-dev bash -l 

 
# In container 
Start monitoring script : php spark queue:listen