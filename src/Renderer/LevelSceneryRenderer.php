<?php

namespace PHPLife\Renderer;

use GL\Geometry\ObjFileParser;
use VISU\ECS\EntitiesInterface;
use VISU\Graphics\BasicVertexArray;
use VISU\Graphics\GLState;
use VISU\Graphics\Rendering\Pass\CallbackPass;
use VISU\Graphics\Rendering\Pass\CameraData;
use VISU\Graphics\Rendering\Pass\GBufferGeometryPassInterface;
use VISU\Graphics\Rendering\Pass\GBufferPassData;
use VISU\Graphics\Rendering\PipelineContainer;
use VISU\Graphics\Rendering\PipelineResources;
use VISU\Graphics\Rendering\RenderContext;
use VISU\Graphics\Rendering\RenderPass;
use VISU\Graphics\Rendering\RenderPipeline;
use VISU\Graphics\ShaderCollection;
use VISU\Graphics\ShaderProgram;
use VISU\Graphics\Texture;

class LevelSceneryRenderer implements GBufferGeometryPassInterface
{
    private ShaderProgram $sceneryShader;

    private array $vaos = [];
    private array $materials = [];
    private array $diffuseTextures = [];

    public function __construct(
        private GLState $gl,
        private ShaderCollection $shaders,
    )
    {
    }

    public function loadLevelObj(string $path) : void
    {
        $model = new ObjFileParser($path);
        /** @var \GL\Buffer\FloatBuffer */
        $meshes = $model->getMeshes('pnc');

        foreach($meshes as $mesh) {
            $va = new BasicVertexArray($this->gl, [3, 3, 2]);
            $va->upload($mesh->vertices);

            if (!isset($this->vaos[$mesh->material->name])) {
                $this->vaos[$mesh->material->name] = [];
            }

            $this->vaos[$mesh->material->name][] = $va;
            $this->materials[$mesh->material->name] = $mesh->material;
        }

        // load all textures
        foreach($this->materials as $material) {
            if ($material->diffuseTexture) {

                // build a path based on the directory of the path of the obj file and the basename of the texture
                $texturePath = dirname($path) . '/' . basename($material->diffuseTexture->path);

                $this->diffuseTextures[$material->name] = new Texture($this->gl, $material->name);
                $this->diffuseTextures[$material->name]->loadFromFile($texturePath);
            }
        }

        // load the shader
        $this->sceneryShader = $this->shaders->get('scenery');
    }

    public function renderToGBuffer(EntitiesInterface $entities, RenderContext $context, GBufferPassData $gbufferData) : void
    {
        $context->pipeline->addPass(new CallbackPass(
            'SceneryPass',
            // setup
            function(RenderPass $pass, RenderPipeline $pipeline, PipelineContainer $data) use($gbufferData) 
            {
                $pipeline->writes($pass, $gbufferData->renderTarget);
            },
            // execute
            function(PipelineContainer $data, PipelineResources $resources) use($entities) 
            {
                $cameraData = $data->get(CameraData::class);

                $this->sceneryShader->use();
                $this->sceneryShader->setUniformMatrix4f('projection', false, $cameraData->projection);
                $this->sceneryShader->setUniformMatrix4f('view', false, $cameraData->view);
                glEnable(GL_DEPTH_TEST);

                foreach($this->vaos as $material => $vaa) {

                    if (isset($this->diffuseTextures[$material])) {
                        $this->diffuseTextures[$material]->bind(GL_TEXTURE0);
                        $this->sceneryShader->setUniform1i('tex_diffuse', 0);
                    }

                    foreach($vaa as $vao) {
                        $vao->bind();
                        $vao->drawAll();
                    }
                }
            }
        ));
    }
}