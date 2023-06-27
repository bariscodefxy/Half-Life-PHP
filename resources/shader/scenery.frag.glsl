#version 330 core

#include "visu/gbuffer_layout.glsl"

in vec3 v_normal;
in vec3 v_position;
in vec4 v_vposition;
in vec2 v_texcoord;

uniform sampler2D tex_diffuse;

void main()
{
    gbuffer_albedo = texture(tex_diffuse, v_texcoord).rgb;
    gbuffer_normal = v_normal;
    gbuffer_position = v_position;
    gbuffer_vposition = v_vposition.xyz;
}