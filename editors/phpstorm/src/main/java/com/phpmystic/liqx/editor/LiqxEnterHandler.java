package com.phpmystic.liqx.editor;

import com.intellij.codeInsight.editorActions.enter.EnterHandlerDelegateAdapter;
import com.intellij.openapi.actionSystem.DataContext;
import com.intellij.openapi.editor.Document;
import com.intellij.openapi.editor.Editor;
import com.intellij.openapi.editor.actionSystem.EditorActionHandler;
import com.intellij.openapi.util.Ref;
import com.intellij.psi.PsiFile;
import org.jetbrains.annotations.NotNull;
import org.jetbrains.annotations.Nullable;

public final class LiqxEnterHandler extends EnterHandlerDelegateAdapter {

    @NotNull
    @Override
    public Result preprocessEnter(
            @NotNull PsiFile file,
            @NotNull Editor editor,
            @NotNull Ref<Integer> caretOffsetRef,
            @NotNull Ref<Integer> caretAdvanceRef,
            @NotNull DataContext dataContext,
            @Nullable EditorActionHandler originalHandler
    ) {
        if (!file.getName().endsWith(".liqx")) {
            return Result.Continue;
        }

        int offset = editor.getCaretModel().getOffset();
        Document document = editor.getDocument();
        CharSequence chars = document.getCharsSequence();

        if (offset <= 0 || offset > chars.length()) {
            return Result.Continue;
        }

        int lineNum = document.getLineNumber(offset);
        int lineStart = document.getLineStartOffset(lineNum);
        String beforeCaret = chars.subSequence(lineStart, offset).toString();
        String lineIndent = getLeadingWhitespace(beforeCaret);

        // Check if caret is between open and close pairs:
        // ( | ), { | }, [ | ], <tag> | </tag>, or ( | ))
        char prev = chars.charAt(offset - 1);
        char next = offset < chars.length() ? chars.charAt(offset) : '\0';

        boolean isBetweenPairs =
                (prev == '(' && (next == ')' || (offset + 1 < chars.length() && chars.charAt(offset + 1) == ')'))) ||
                (prev == '{' && next == '}') ||
                (prev == '[' && next == ']') ||
                (prev == '>' && next == '<');

        if (isBetweenPairs) {
            String indent = lineIndent + "  ";
            String toInsert = "\n" + indent + "\n" + lineIndent;
            document.insertString(offset, toInsert);
            editor.getCaretModel().moveToOffset(offset + 1 + indent.length());
            return Result.Stop;
        }

        // Check if line before caret ends with an opening construct:
        // => (, (, {, [, =>, <schema>, <tag>
        String trimmedBefore = beforeCaret.trim();
        if (trimmedBefore.endsWith("(") ||
            trimmedBefore.endsWith("{") ||
            trimmedBefore.endsWith("[") ||
            trimmedBefore.endsWith("=>") ||
            (trimmedBefore.endsWith(">") && !trimmedBefore.endsWith("/>") && !trimmedBefore.startsWith("</"))) {

            String indent = lineIndent + "  ";
            document.insertString(offset, "\n" + indent);
            editor.getCaretModel().moveToOffset(offset + 1 + indent.length());
            return Result.Stop;
        }

        return Result.Continue;
    }

    private static String getLeadingWhitespace(String s) {
        int i = 0;
        while (i < s.length() && (s.charAt(i) == ' ' || s.charAt(i) == '\t')) {
            i++;
        }
        return s.substring(0, i);
    }
}
