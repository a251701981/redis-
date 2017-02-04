/**
*@description:基础精灵，基本的宽高、位置、背景图片
其中img:{url:图片路径，left:水平偏移，top:垂直偏移}
*/
function BaseFairy(width,height,x,y,img)
{
	this.position = 'absolute';
    this.width = width + 'px';
	this.height = height + 'px';
	this.left = x + 'px';
	this.top = y + 'px';
	this.background = 'url('+img.url+') no-repeat '+img.left+'% '+img.top+'%';
	
	this.getter = function(key)
	{
		return parseInt(this[key]);
	}
}