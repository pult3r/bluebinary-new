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
For production : docker-compose -f docker-compose-prod.yml up --build<br/>
For development docker-compose -f docker-compose-dev.yml up --build<br/>


## Go to docker container : 
For production docker exec -it bluebinary-app-prod bash -l<br/>
For development : docker exec -it bluebinary-app-dev bash -l <br/>

 
# In container 
Start monitoring script : php spark queue:listen

## For test you can use postman_collection.json : 
https://github.com/pult3r/bluebinary-new/blob/main/Bluebinary.postman_collection.json
