/**
*@description:基础精灵，基本的宽高、位置、背景图片
*其中img:{url:图片路径，left:水平偏移，top:垂直偏移}
*/
function BaseFairy(width,height,x,y,img)
{
	this.objPool = {};  //所在对象池的引用
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
/**
*@description:活动精灵，实现帧、移动
*@param FUNCTION frameFunc 第一帧的回调
*/
function ActionFairy(width,height,x,y,img,frameFunc)
{
	BaseFairy.call(this,width,height,x,y,img);
	this.frameList = [];  //帧列表
	this.curFrame = 0;    //当前第几帧
	this.fps = 16;        //帧率
	var timer;//存储定时器id
	this.init = function()
	{
		if(typeof(frameFunc) != 'function')
		{
			this.frameList[0] = function(){};
		}else{
			this.frameList[0] = frameFunc;
			
			frameFunc.call(this);
		}
			
	}
	
	//获取在对象池中的引用
	this.getSelfForPool = function()
	{
		return this.objPool[parseInt(this.left)+'_'+parseInt(this.top)];
	}
	//移动到某个像素
	this.moveTo = function(x,y)
	{
		this.left = x + 'px';
		this.top  = y + 'px';
	}
	//水平移动x个像素
	this.xMove = function(x)
	{
		this.left = (parseInt(this.left) + x) + 'px';
	}
	//垂直移动y个像素
	this.yMove = function(y)
	{
		this.top = (parseInt(this.top) + y) + 'px';
	}
	//添加一帧
	this.addFrame = function(func)
	{
		this.frameList.push(func);
	}
	//按当前顺序播放帧
	this.play = function()
	{
		var self = this;
		if(typeof(timer) != 'undefined') return;
		if(this.frameList.length <= 1) return;
		
		timer = setInterval(function(){
			self.curFrame+= 1
			if(self.curFrame == self.frameList.length-1) self.curFrame = 0;
			var func = self.frameList[self.curFrame];
			if(typeof(func) != 'function') return;
			var poolObj = self.getSelfForPool();
			func.call(self);
		},1000/this.fps);
	}
	this.stop = function()
	{
		if(typeof(timer) == 'undefined') return;
		clearInterval(timer);
		timer = undefined;
	}
	this.init();
}