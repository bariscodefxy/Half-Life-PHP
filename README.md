# VISU Quickstart

This is a quickstart guide to create a minimal and lightweight VISU application for rapid prototyping.

Using this quickstart will provide you with the bare minimum code and scaffolding for a simple VISU application. 
If you're looking for the **full scaffolding** for more complex applications where you maintain full control, check out [VISU Starter](https://github.com/phpgl/visu-starter).

The Quickstart will provide the following:

 * A minimal application class `src/Application.php`.
   - `draw` method with a simple rendering pipeline prebuilt.
   - `update` method which is called at a fixed interval.
 * Simple game loop metrics, showing the current FPS, frame times, and update times.
 * Event handling and Input Events.
   - An `InputContext` with no actions assigned. 
 * Offscreen rendering to a texture by default. 
 * Properly resizing render target and HDPI handling.
 * ECS (Entity Component System) is available. 
 * Vector Graphics frame is preinitialized.

## Prerequisites

 * PHP 8.1 or higher
 * PHP-GLFW extension installed
 * Composer

## Usage

Use Composer to create a new project based on visu-quickstart:

```bash
composer create-project phpgl/visu-quickstart -s dev --prefer-dist my-visu-project 
```

After the installation is complete, you can start the application by running:

```bash
cd my-visu-project
php ./bin/start.php
```