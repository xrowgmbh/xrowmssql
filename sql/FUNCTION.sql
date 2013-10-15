SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
-- =============================================
-- Author:		Björn Dieding
-- Create date: 12.12.2006
-- Description:	A Wrapper function for LEN()
-- =============================================
IF OBJECT_ID (N'dbo.LENGTH', N'FN') IS NOT NULL
    DROP FUNCTION dbo.LENGTH;
GO
CREATE FUNCTION LENGTH 
(
	-- Add the parameters for the function here
	@STRING varchar
)
RETURNS int
AS
BEGIN
	-- Declare the return variable here
	DECLARE @Result int

	-- Add the T-SQL statements to compute the return value here
	SELECT @Result = LEN ( @STRING )

	-- Return the result of the function
	RETURN @Result

END
GO

