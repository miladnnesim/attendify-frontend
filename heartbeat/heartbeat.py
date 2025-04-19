import pika
import time
import os
import logging
from datetime import datetime
import xml.etree.ElementTree as ET
import docker  # Add docker library

logging.basicConfig(level=logging.INFO)

# RabbitMQ connection parameters
RABBITMQ_HOST = 'rabbitmq'
RABBITMQ_PORT = os.environ.get('RABBITMQ_AMQP_PORT')
RABBITMQ_USERNAME = os.environ.get('RABBITMQ_USER')
RABBITMQ_PASSWORD = os.environ.get('RABBITMQ_PASSWORD')
RABBITMQ_VHOST = os.environ.get('RABBITMQ_HOST')

# Heartbeat-specific parameters
SENDER = 'Frontend'
EXCHANGE_NAME = 'monitoring'
QUEUE_NAME = 'monitoring.heartbeat'
ROUTING_KEY = 'monitoring.heartbeat'

def create_heartbeat_message(container_name):
    # Create XML structure
    root = ET.Element('attendify')
    info = ET.SubElement(root, 'info')
    ET.SubElement(info, 'sender').text = SENDER
    ET.SubElement(info, 'container_name').text = container_name
    ET.SubElement(info, 'timestamp').text = datetime.utcnow().isoformat() + 'Z'
    return ET.tostring(root, encoding='utf-8', method='xml')

def get_running_containers():
    # Initialize Docker client
    client = docker.from_env()
    # Get list of running containers
    return client.containers.list()

def main():
    # RabbitMQ connection
    credentials = pika.PlainCredentials(username=RABBITMQ_USERNAME, password=RABBITMQ_PASSWORD)
    connection = pika.BlockingConnection(
        pika.ConnectionParameters(host=RABBITMQ_HOST, port=RABBITMQ_PORT, credentials=credentials, virtual_host=RABBITMQ_VHOST)
    )
    channel = connection.channel()

    logging.info(f"Starting heartbeat for all running containers to exchange {EXCHANGE_NAME} with routing key {ROUTING_KEY}")
    try:
        while True:
            # Get all running containers
            containers = get_running_containers()
            for container in containers:
                container_name = container.name
                message = create_heartbeat_message(container_name)
                channel.basic_publish(
                    exchange=EXCHANGE_NAME,
                    routing_key=ROUTING_KEY,
                    body=message,
                    properties=pika.BasicProperties(delivery_mode=2)  # Persistent messages
                )
                logging.info(f"Sent heartbeat for {container_name}: {message.decode('utf-8')}")
            time.sleep(1)  # Wait 1 second before next iteration
    except KeyboardInterrupt:
        logging.info("Heartbeat stopped by user")
    finally:
        connection.close()

if __name__ == "__main__":
    main()